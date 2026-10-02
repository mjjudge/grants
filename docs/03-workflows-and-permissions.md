# Workflows and permissions

Adapted from the original build pack — capability names corrected to the
`grants_` prefix (see `CLAUDE.md` and `backlog/DECISIONS.md`), and a staff
notification workflow added to Communication (missing from the original brief).

## Funding round

Draft → Open → Closed → Archived. Opening validates dates, the configured help
email, the notification recipient list, the privacy notice, policy wording, and
budget configuration. Server time enforces the deadline even if a stale cached
form is visible. Dates are entered in site timezone and stored UTC; opening
inclusive, closing exclusive. A late staff entry after closure needs a
capability, reason, and source label. Archived records remain readable to
authorised users.

## Application

Received → Under review → More information requested → Under review → Decided.
Withdrawal is possible from Received, Under review, or More information
requested. Duplicate is a staff classification with a link to the retained
application, not a deletion. A decided application can be reopened only by an
authorised actor with a reason; the previous decision remains recorded. If an
award exists, reopening does not silently release or cancel it.

Track eligibility findings separately from application status. Public
declarations are not verification. A reviewer records checks and uncertainty;
decision makers decide how to handle it. Requests for more information are
drafted in admin, then explicitly sent by a staff action. Replies can be entered
as dated addenda in the first release. No unauthenticated applicant editing
endpoint is needed.

## Decision and award

Allow approve, decline, or defer. Approve requires requested amount visibility,
approved amount, reason, decision date, responsible actor, and optional meeting
reference/conditions. Partial awards are supported. Approval atomically creates
or revises the award and reserves budget. Defer returns the application to
review without an award. Decline records a reason with no award.

The first release records a committee decision, rather than implementing
committee voting. A reviewer recommendation alone cannot create an award.
Conflict-of-interest declarations are required for reviewing or deciding;
declared conflicts block those actions for that user unless a separately
authorised, recorded policy exception is configured. Discover the committee's
actual policy before launch.

Award status is Approved, Cancelled, or Superseded. Payment progress is
calculated as Unpaid, Part paid, or Paid from the ledger; it is not an editable
checkbox. Award conditions and their fulfilment are staff-managed. Before the
treasurer can record a payment, required pre-payment conditions must be marked
fulfilled with evidence notes.

## Payment recording

The treasurer confirms the recipient and bank details using the existing
independent process, makes payment outside the site, then records amount,
actual date, method, and reference. No bank account fields or transfer buttons
in WordPress. Reject amounts above outstanding approval. Historical entries can
specify a past payment date with a provenance note; `created_at` still records
the actual entry time.

A correction creates a reversal and, if necessary, a replacement payment. Both
remain visible. A reversal is a correction to the website ledger; it must not
imply a bank refund occurred unless staff explicitly record the real-world
event.

## Proposed capabilities

Create explicit custom capabilities, prefixed `grants_` (see `CLAUDE.md` —
deliberately not `tolg_` or anything Tree-of-Light-specific). Allocate them to
named staff accounts after committee agreement; **do not grant them
indiscriminately to all editors** — this is a hard rule here, not just a
recommendation, given Tree of Light's own `Editor`-auto-grant is a known,
deliberately-deferred gap on that plugin (see `docs/grants-discovery.md`). Site
administrators configure initial roles. No shared committee login.

| Capability | Coordinator | Reviewer | Decision maker | Treasurer |
|---|---|---|---|---|
| Read applications and organisation history | Yes | Yes | Yes | Yes |
| Edit current organisation/contact data; add amendments | Yes | No | No | No |
| Link/merge organisation identities | Yes | No | No | No |
| Add review and recommendation | Optional | Yes | Yes | No |
| Approve/decline/revise decision | No | No | Yes | No |
| Record/reverse payment | No | No | No | Yes |
| Export committee information | Yes | No by default | Yes | No |
| Export financial report | No by default | No | Yes | Yes |
| Export future-round contact list | Yes | No | No | No |
| Manage rounds, budgets, and configuration (including Settings/notification recipients) | Separately assigned | No | Separately assigned | No |
| Execute retention/anonymisation | Separately assigned | No | No | No |

Role combinations are possible for a small club. Document who holds them.
Enforce capabilities at each endpoint and object/action level, not just menu
visibility. User input must not change role assignments or trusted actor IDs.

## Communication

**Applicant acknowledgement:** application reference, round, received date,
amount requested, and help route. Avoid internal notes, unrelated organisation
history, and sensitive beneficiary details. Do not state that eligibility is
confirmed.

**Staff notification of a new application** *(added — not in the original
build pack's Communication section)*: sent automatically, to every address
configured in Settings' notification recipient list, at the same point the
applicant's acknowledgement is queued — after the application has committed,
never before, and a failure to send it must not block or duplicate the saved
application (same discipline as the acknowledgement). Content: application
reference, round/fund name, organisation name, requested amount, and a direct
admin link to the application — enough for a coordinator to know something
needs attention, not a full copy of the submitted answers. This is a distinct
notification `kind` in `grants_notifications` (see
`docs/02-architecture-and-data.md`), with its own retry/failure visibility, not
a side effect bolted onto the applicant email.

**Decision message:** staff-reviewed outcome, amount if approved, conditions,
and next steps. Record the decision independently of email sending. Only an
explicit staff action sends a decision notice; editing a note does not send
another email. Future-round invitations and bulk sending are later work; first
release exports only permitted, active contact records.
