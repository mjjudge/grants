# Rotary Grants — WordPress Plugin

**Owner:** Rotary in the Vale  
**Repository:** not yet published  
**Version:** 0.12.0 (G11a committee page — see "Current status" below)  
**Requires:** WordPress 6.0+, PHP 8.2+ (provisional — see `docs/ARCHITECTURE.md`)  
**Hosted on:** SiteGround shared hosting (same account as the sibling plugins below)

---

## What this is

A WordPress plugin that keeps the persistent record for Rotary in the Vale's
grant-giving: organisations, funding rounds, submitted applications, committee
decisions, approved awards, and the actual payment ledger. **It is not scoped
to Tree of Light** — the club raises money through more than one fund, and a
funding round is a generic concept (each carries its own `fund_name`) so the
same plugin serves whichever fund is currently running a round.

It never moves money. The committee decides awards; the treasurer makes
payments through the existing independent banking process and records them
here afterwards.

This plugin is a sibling to, and entirely independent of:
- `~/myapps/TOL` — Tree of Light fundraising plugin
- `~/myapps/duck-race` — Duck Race fundraising plugin
- `~/myapps/battle-shield-sponsorship` — Battle of Evesham Shield Sponsorship plugin

It shares no code, database table, or credential with any of them — see
`docs/grants-discovery.md` for the evidence this was decided from.

## Current status

**G01–G09, G11 and G11a done — 0.12.0** (G10 deferred). The plugin activates, creates its tables, grants
the `grants_*` capabilities to Administrator only (never Editor), and provides:

- **Public application form** — put
  `[rotary_grant_application fund="Tree of Light"]` on a page (one page per
  fund; `round="N"` pins a specific round). The form shows that round's own
  wording, enforces the closing time by server clock, keeps answers on
  error, and saves each application exactly once even on double-clicks or
  retries. See `deployment-notes/CACHE_EXCLUSIONS.md` before going live.
- **Emails** — on each submission the applicant gets an acknowledgement and
  every Settings recipient gets a short "new application" notice, sent from
  `funds@rotaryinthevale.org` (configurable) with retries; failures show on
  **Notifications**. Sending can be paused in Settings
- **Applications** (admin) — list and full submitted snapshot; staff link
  each one to an **organisation** from suggested matches (never automatic),
  and can **enter paper/email/phone applications** (labelled, late ones
  flagged with a reason)
- **Committee page on the website** — `[rotary_grants_committee]` on a page
  set in Settings: reviewers and decision makers sign in there (not WordPress
  admin) to declare conflicts, review, add notes and record decisions; same
  rules as admin; review-only users are kept out of WordPress admin
- **Committee review** — conflict-of-interest declarations before taking part
  (any conflict blocks reviewing/deciding and hides the discussion), reviews
  with eligibility findings and recommendations, internal notes, requests for
  more information (drafted, then sent), applicant replies, withdrawal,
  duplicates; per-round declarations screen and conflicts register;
  committee list filters
- **Decisions and awards** — approve (incl. part awards) / decline / defer
  with reasons and dates, conditions, reopening and revisions; budget
  reserved on approval, concurrent approvals serialised; going over a round's
  budget needs a confirmation and a note of where the extra money comes
  from; decision notices drafted then sent; Awards screen with round totals
- **Payments** — the treasurer records payments already made (bank transfer,
  cheque, other) once pre-payment conditions are met; partial payments,
  reversals for corrections, outstanding and Unpaid / Part paid / Paid
  derived from the ledger; never more than outstanding; no bank details and
  no transfers
- **Reports** — round overview, committee list, awards & payments by round,
  payments by date (reporting year set in Settings: calendar, Rotary year or
  accounting year), future-round contacts; formula-safe CSV exports, each
  behind its own capability and audited
- **Privacy & retention** — retention periods in Settings (suggested,
  confirmable), dry run then Apply, anonymise-not-delete (awards and payments
  always kept), WordPress Export/Erase Personal Data covers the plugin;
  uninstall keeps data (see `deployment-notes/UNINSTALL_AND_ERASE.md`)
- **Organisations** — corrections with history, contacts over time,
  future-round email permissions with withdrawal, merging duplicates
- **Funding Rounds** — create, edit, open, close, reopen and archive rounds
- **Settings** — help email, staff notification recipients, privacy notice
- **Access** (administrators) — who holds which capability

Historical import is deferred (no historical data — DEC-017). See
`backlog/DECISIONS.md` DEC-007 to DEC-019. G12 (release and staff guide) is
next.

---

## Technology stack

| Layer | Choice | Notes |
|---|---|---|
| Platform | WordPress | Hosted on SiteGround, same account as the sibling plugins |
| Plugin | Custom (this repo) | No dependency on paid plugins, no Composer |
| Database | MySQL via `$wpdb` | SiteGround is master system of record; no payment gateway anywhere |
| Payments | None — recorded only | The treasurer pays outside WordPress; this plugin logs it afterwards |
| Email | WordPress `wp_mail()` | Routed through SiteGround SMTP, same as the sibling plugins |

## Repository structure

```
plugin/                    WordPress plugin (the application)
  src/
    Core/                   Bootstrap: Plugin (boot), Installer (activate/
                             deactivate), Roles (capability list)
    Admin/                  Thin admin controllers (menu, pages, profile section)
    Services/               Business rules — the only code that writes data
    Database/               Migrator + one class per schema change
    Mail/                   Mailer (scoped sender identity, plain text)
    Public/                 Public form handler (shortcode, tokens, submission)
    Support/                Money (exact GBP), SiteTime (timezone), NameMatcher
    Audit/                  AuditLogger → grants_audit_events
  assets/
    css/
    js/
  templates/
    admin/
    email/
    public/
  rotary-grants.php         Plugin entry point, version constant, autoloader,
                             activation/deactivation hooks, boot

docs/                       Product brief, architecture, security/privacy,
                             workflows, decisions-needed, and the repository
                             discovery this plugin's design was based on
scripts/build-zip           Packages a release — see "Building a release" below
dist/                       Versioned release ZIPs (rotary-grants-<version>.zip)
test-notes/                 Manual test plan (no automated framework — see CLAUDE.md)
deployment-notes/           Release checklists and rollback procedures
backlog/                    BACKLOG.md and DECISIONS.md — kept locally, not
                             published (see .gitignore), same as Tree of Light
CLAUDE.md                   Agent/coding-discipline instructions — kept locally,
                             not published (see .gitignore)
```

### Building a release

```
scripts/build-zip
```

Reads the version from `plugin/rotary-grants.php`, packages the `plugin/`
folder as `rotary-grants/` (matching the
`wp-content/plugins/rotary-grants/` install path), and writes
`dist/rotary-grants-<version>.zip`. Bump the `Version:` header and the
`GRANTS_VERSION` constant in `plugin/rotary-grants.php` first, add a line to
the Version history table below, then run the script. Matches the convention
used by Tree of Light, Duck Race, and Battle of Evesham Shield Sponsorship —
confirmed directly from those repositories' own `scripts/build-zip`, not
assumed.

**If the release includes a new database migration, a deactivate/reactivate
cycle is required on the live site to run it** — see `CLAUDE.md` and
`deployment-notes/RELEASE_CHECKLIST.md`.

---

## Where to start

1. Read `CLAUDE.md` for the coding/testing discipline this repository expects —
   it combines the product brief in `docs/` with conventions actually proven
   across the sibling plugins' release history.
2. Read `docs/00-proposal.md` through `docs/08-source-notes.md` for the product
   brief, and `docs/grants-discovery.md` for the evidence it's grounded in.
3. Read `backlog/BACKLOG.md` for the ordered task list (G00–G09 and G11 are
   done, G10 is deferred; G12 is next) and `backlog/DECISIONS.md` for what's already been decided.
4. Confirm the still-open rows in `docs/07-decisions-and-launch.md` with the
   project owner before opening a live funding round — none of them are
   guessed, and none should be.

---

## Version history

| Version | Summary |
|---|---|
| 0.1.0 | Repository setup: git init, directory skeleton, `scripts/build-zip` (proven working), `docs/` (product brief adapted from the original build pack, naming corrected to be fund-agnostic, Settings/staff-notification requirement added), `backlog/BACKLOG.md` and `backlog/DECISIONS.md`, `CLAUDE.md`, `test-notes/TEST_PLAN.md`, `deployment-notes/`. No functional plugin code yet — see "Current status" above |
| 0.2.0 | G01 bootstrap: activation/deactivation, `Migrator` with `grants_audit_events` and `grants_settings` tables, `grants_*` capabilities granted to Administrator only, per-user capability grants on the user profile (admin-only, audited), Rotary Grants menu with placeholder dashboard, Settings screen (help email, staff notification recipients), Access screen. **Contains migrations — deactivate/reactivate required when upgrading.** |
| 0.3.0 | G02 funding rounds: `grants_rounds` table, round list/add/edit screens, draft→open→closed→archived status changes with readiness checks, one open round per fund (lock-serialised), server-time accepting window (opening inclusive, closing exclusive), exact GBP parsing, timezone handling that rejects non-existent/ambiguous clock-change times, per-round wording with automatic wording version, optimistic concurrency; privacy notice link/version added to Settings. **Contains a migration — deactivate/reactivate required when upgrading.** |
| 0.4.0 | G03 application form: `[rotary_grant_application fund="…"]` shortcode (one page per fund, concurrent funds supported), every docs/01 field with server-side validation and accessible errors that keep answers, per-round publicity/presentation wording (`presentation_text` column), nonce + session-bound HMAC token + origin check + honeypot + rate limit, idempotent InnoDB submission (`grants_applications`, `grants_submissions`), server-time closure, session-bound receipt, read-only admin Applications list/view. **Contains migrations — deactivate/reactivate required when upgrading.** |
| 0.5.0 | G04 notifications: `grants_notifications` queue (unique command key per message, queued after commit), applicant acknowledgement and per-recipient staff notice (plain text, From `funds@rotaryinthevale.org` / Rotary in the Vale, Reply-To help email — editable in Settings), send-after-response plus 5-minute WP-Cron retries with backoff, failed-email screen with retry, pause switch, receipt mentions the acknowledgement. **Contains a migration — deactivate/reactivate required when upgrading.** Before launch: SPF/DKIM for the sender and a real cron job (see release checklist). |
| 0.6.0 | G05 organisations: `grants_organisations`, `grants_contacts`, `grants_preferences`, `grants_amendments` tables and staff-entry columns; staff-confirmed linking of applications from suggested matches (name/charity number/postcode — never email), contacts over time, correction history, future-round permission history with withdrawal, merging duplicates, repeat-application flag, staff-entered paper/email/phone applications with source label and late flag. **Contains migrations — deactivate/reactivate required when upgrading.** |
| 0.7.0 | G06 committee review: `grants_conflicts`, `grants_reviews`, `grants_application_notes` tables, duplicate and notification-link columns; conflict-of-interest declarations enforced in services (based on RI grants COI policy and Charity Commission CC29), reviews with eligibility findings and versioned history, notes, info requests emailed on explicit send, addenda, status workflow, duplicates, declarations screen and conflicts register, committee list filters and previous-application history. **Contains migrations — deactivate/reactivate required when upgrading.** |
| 0.8.0 | G07 decisions and awards: `grants_decisions`, `grants_awards`, `grants_award_conditions`; approve/decline/defer with reasons, dates, revisions and reopening; budget reservation serialised on the round row; over-budget approvals only with confirmation and a funding note (shown in totals); conditions with evidence; decision notices drafted then sent; Awards screen; round budget can't be lowered below approvals. **Contains migrations — deactivate/reactivate required when upgrading.** |
| 0.9.0 | G08 payment ledger: `grants_payments` (append-only payments and reversals, one-time command keys), treasurer Payments and award screens, pre-payment conditions enforced, payments capped at outstanding and serialised per award, reversals capped at the unreversed amount with refund recorded separately, derived progress; decisions can't go below what's paid. **Contains a migration — deactivate/reactivate required when upgrading.** |
| 0.10.0 | G09 reports and exports: round overview, committee list (tallies hidden where you declared a conflict), awards & payments by round, payments by reporting year (new Settings → Reports start-month, default calendar year), future-round contacts (current, un-withdrawn opt-ins only); formula-safe typed CSV exports per capability, audited; organisation page shows awarded/paid. **Generic defaults:** email sender now defaults to the site name / WordPress sender — enter the club's sender in Settings. No migration. |
| 0.11.0 | G11 privacy and retention: retention periods in Settings (suggested 24/84/36/12 months, trustee-confirmation box), Privacy & Retention screen with dry run and Apply (anonymise personal details and free text; awards, payments and decisions kept), WordPress personal-data exporter and eraser (open applications kept, shared addresses flagged), daily removal of expired submission keys, round-opening warning until periods confirmed, data-keeping `uninstall.php`, privacy-policy text. **Contains a migration — deactivate/reactivate required when upgrading.** |
| 0.12.0 | G11a committee page: `[rotary_grants_committee]` website page (chosen in Settings) where reviewers and decision makers sign in, see their rounds and what needs attention, declare conflicts (singly or per round), review, add notes and record decisions (incl. over-budget confirmation and funding note, conditions, reopen); posts through the same handlers and services as admin; review-only users redirected from WordPress admin (profile excepted) with no admin bar; page never cached or indexed. No migration. |
