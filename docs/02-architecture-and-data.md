# Architecture and data model

Adapted from the original build pack. Naming corrected against the decisions in
`backlog/DECISIONS.md` (plugin slug `rotary-grants`, namespace `Rotary\Grants\`,
table prefix `grants_` — not `tolg_`/`Rotary\TreeOfLight\Grants`, since this
plugin is not Tree-of-Light-specific). A `fund_name` field and a Settings table
have been added; everything else is the original brief's requirement.

## Plugin boundary

Slug `rotary-grants`, PHP namespace `Rotary\Grants\`, shortcode
`[rotary_grant_application fund="…"]` (confirmed by the project owner at G03;
`round="N"` pins a specific round instead — see `backlog/DECISIONS.md`
DEC-010). No collision with any shortcode already registered by Tree of
Light, Duck Race, or Battle Shield Sponsorship (checked — see
`docs/grants-discovery.md`). Build conventional PHP WordPress screens and a
progressively enhanced public form; no separate service or SPA is needed.

Use custom tables for related financial and application records, with
`$wpdb->prefix`, correct charset/collation, and versioned migrations (mirror
Tree of Light's own `Migrator`/`MigrationInterface` pattern exactly — see
`CLAUDE.md`). WordPress documents `dbDelta` for table evolution, but indexes,
backfills, and constraints require explicit verification on the actual host.
Confirm transactional table support before relying on transactions. Do not
assume a rollback of plugin files reverses database migrations.

Suggested code divisions: `Core\Installer`, `Core\Plugin`, `Core\Roles`
(mirroring Tree of Light's own `Core/` module exactly), `Database\Migrator` +
`Database\Migrations\*`, `Services\*Repository`/`*Service` classes
(`ApplicationService`, `OrganisationService`, `DecisionService`,
`PaymentService`, `NotificationService`, `SettingsService`, `PrivacyService`),
`Admin\*` screens, and `Public\*` form handlers. Centralise money parsing,
permission checks, and state transitions in services. Keep browser JavaScript
out of business-rule enforcement.

## Records

Every table has a numeric internal primary key. Monetary values are integer
pence in signed BIGINT columns; positive inputs have separately defined
reversal entries. Store timestamps in UTC and render them in the configured
WordPress timezone. Validate GBP explicitly; do not use floating-point
arithmetic.

| Record and table suffix | Important fields | Relationships and constraints |
|---|---|---|
| Funding round `grants_rounds` | label, **fund_name**, campaign_year, optional accounting_period_label, opens_at, closes_at, status, budget_pence, optional cap_pence, policy_version, form_version | Unique internal ID; display label may differ from payment year; `fund_name` identifies which fund this round belongs to (e.g. "Tree of Light", or another fund the club runs) so the plugin serves more than one fund without a schema change; opening needs configured help email, notification recipients, and privacy notice |
| Organisation `grants_organisations` | current name, charity_number nullable, locality, website, status, merged_into_id nullable | Stable internal ID; charity number and name are matching signals, not universal unique keys. **Not the same table as any sponsor/donor "company" record in a fundraising plugin** — see Do not reuse Tree of Light's tables, below |
| Contact `grants_contacts` | organisation_id, name, role, email, phone, active_from, active_until nullable | Multiple contacts and changes over time; no global email uniqueness constraint |
| Contact preference `grants_preferences` | contact_id, purpose, status, wording_version, recorded_at, withdrawn_at, evidence_source | Purpose-specific permission history; do not overwrite withdrawal evidence |
| Application `grants_applications` | round_id, organisation_id nullable pending match, contact_id nullable, public_reference, status, answer_snapshot_json, requested_pence, form_version, submitted_at, source, row_version | Unique public reference and submission key; submitted snapshot is immutable; historical manual imports may lack submission date |
| Submission `grants_submissions` | key_hash, payload_hash, application_id, created_at, expires_at | Unique key; atomic application creation and retry handling; mismatched replay rejected |
| Review `grants_reviews` | application_id, reviewer_user_id, eligibility findings, recommendation, notes, conflict declaration, reviewed_at | Internal; no public exposure; amendment history retained |
| Decision `grants_decisions` | application_id, type, approved_pence, conditions, reason, meeting_reference, decided_at, actor_user_id, supersedes_id | Append revisions; one effective decision determined transactionally; rejected decision has no award |
| Award `grants_awards` | organisation_id, round_id, application_id nullable, effective_decision_id nullable, approved_pence, status, approved_at nullable, source, provenance | At most one effective award per application; application nullable for historical awards without an original form |
| Payment entry `grants_payments` | award_id, entry_type payment/reversal, amount_pence, paid_at, method, reference, actor, reversed_payment_id nullable, reason | Append-only; reversals reference the original entry; no duplicate command ID; no deletion to correct a payment |
| Audit `grants_audit_events` | actor, entity_type/id, action, timestamp, limited change summary, request_id | Append-only; no raw tokens, bank information, or full answers in logs; not tamper-proof against DB administrators |
| Notification `grants_notifications` | application_id, kind, recipient, status, attempts, next_attempt_at, last_error_code, command_key | Unique semantic command key; retries logged; mail acceptance is not proof of delivery. `kind` includes both the applicant acknowledgement and the staff "new application" notification (see Settings, below) |
| Import batch `grants_import_batches` | file fingerprint, actor, date, source label, row outcomes | Dry run, provenance, and duplicate prevention; restrict source-file retention |
| **Settings** `grants_settings` *(added — not in the original brief)* | key, value, updated_at, updated_by_user_id | Simple key/value store, same shape as a WordPress options table but scoped to this plugin and capability-gated for editing. Holds: help/contact email, **notification recipient list** (one or more staff addresses notified on a new application), privacy notice link/version, and any other admin-configurable text used by the public form or notification emails |

Use indexes on round/status/submitted_at, organisation/round, award/payment
date, and preference status. Validate all entity ownership and references in
the service layer. Discover which database constraints are reliable on the
supported host rather than assuming `dbDelta` creates foreign keys.

## Do not reuse Tree of Light's tables

Tree of Light already has `tol_companies` and `tol_contacts` tables (added for
its own business-sponsorship feature). A Tree of Light "company" is a business
*giving* money to that fundraising campaign; a Rotary Grants "organisation" is a
community group *requesting* money from a fund. These are different
relationships with different lawful bases and retention needs — reusing either
table, or copying contacts between the two plugins, would repurpose personal
data for a new purpose without the documented assessment the privacy brief
requires (`docs/04-security-and-privacy.md`). Keep the schemas, and the two
plugins, structurally independent. See `docs/grants-discovery.md` for the full
reasoning.

## Preserve history and resolve identity

The application snapshot includes organisation and contact details, all
answers, and the exact declaration, publicity, policy, and notice versions
shown. A later contact change must not alter a previous application.
Corrections are dated amendments, with original values retained until lawful
retention processing.

Public submissions create an application and a pending identity match. Staff
see possible organisations based on normalised name, charity number, and
locality. Linking requires staff review; shared emails alone never match
organisations. Creating a new organisation is safe when uncertain. A staff merge
keeps a redirect, moves links transactionally, records a reason, preserves
snapshots, and keeps an audit trail. Do not merge automatically.

Repeated submissions are permitted by default but flagged within a round. The
committee must decide whether one application per organisation per round is a
rule; do not impose it silently. Request retries are handled by idempotency, not
by blocking all matching email addresses.

## Financial semantics

For a round, **approved commitments** = sum of current, active approved award
amounts. **Available to award** = round budget minus approved commitments.
**Net paid** = payment entries minus reversal entries. **Outstanding
commitment** = approved commitments minus net paid. Payments do not reduce
available-to-award a second time.

Example: budget £10,000, awards £3,000, payments £1,000. Available to award is
£7,000; outstanding is £2,000. A £200 reversal reduces net paid to £800 and
increases outstanding to £2,200; it does not change the £3,000 approval.

Over-budget approvals fail by default. An authorised override, if the committee
wants one, requires a distinct capability and recorded reason; otherwise change
the round budget with audit first. Serialise award decisions on the round row
so concurrent approvals cannot overcommit. Serialise payments on the award row
and reject payments above remaining approved amounts.

Award cancellation/amendment cannot reduce approval below net payments already
made. Correct actual payments through reversal records first when warranted. A
reversal cannot exceed the unreversed amount of its original payment. Require
explicit effective decision revisions; never edit historical payments in place.

Reporting defaults to funding round and separately offers payment-date totals.
A 2026 award paid in 2027 stays in the 2026 round and appears in 2027 payment
reporting. Historical award-only records must not be counted as new submitted
applications. Unknown historic amounts/dates remain unknown, not zero or
invented dates.

Historical imports may contain an award with an unknown approved amount.
Represent that as nullable with an explicit incomplete-history flag. Show counts
of incomplete records beside totals; do not present known-value sums as
complete totals. An imported record with unknown approval cannot accept new
payment entries through the normal workflow until authorised staff reconcile it.
Historic payments with an unknown date cannot be assigned to a payment-year
total; show them separately.
