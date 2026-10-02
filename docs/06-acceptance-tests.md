# Acceptance tests

Carried over from the original build pack, with a settings/notification
scenario added (missing from the original). These are implementation targets,
not checks already passed. Use synthetic organisations, staff accounts, and
test inboxes. There is no automated test framework in this repository (see
`CLAUDE.md`'s Testing discipline section) — verify these manually via
`test-notes/TEST_PLAN.md`, and with throwaway CLI verification scripts for
anything with real logic (money math, state transitions, concurrency), not by
code review alone.

## Settings and notifications *(added)*

- Opening a round is blocked until the help email and at least one notification
  recipient are configured.
- A successful submission queues both the applicant acknowledgement and the
  staff notification in the same transaction-committed step; a mail failure on
  either does not lose or duplicate the saved application.
- Every address in the configured notification recipient list receives the new
  staff notification; removing an address from Settings stops future
  notifications to it without affecting already-sent ones.
- The staff notification contains no full copy of submitted answers — reference,
  round/fund name, organisation name, amount, and an admin link only.
- A round from a different fund (different `fund_name`) shows its own
  configured wording and help email on its public form, not another round's.

## Applicant and identity

- A community group with no charity number can apply. A local national-organisation branch can explain its separate management and local benefit.
- Every original eligibility rule and exclusion is visible. No invented award cap or geographic radius appears.
- Required and invalid values return accessible field errors; entered answers survive. Keyboard-only and mobile use work; labels, focus, and error summary are correct.
- Accept apostrophes, accented names, and normal punctuation. Reject script payloads, unsafe URLs, malformed/negative money, more than two decimal places, oversized body, and forged staff fields.
- Closing boundary uses server time in the configured timezone. A previously loaded form cannot submit after closure. A legitimate staff exception is recorded distinctly.
- Two concurrent POSTs with the same submission key save one application and return the same reference. Different payload with the same key is rejected. A genuine later application is not silently suppressed by matching email.
- Submission success is durable before receipt or notification. A database failure shows no success. A mail failure leaves the application in admin and displays an accurate receipt.
- Two organisations sharing one contact email remain separate. Similar names create staff match candidates, not an automatic merge. A merge preserves applications and records its reason.
- A later change of contact/name does not rewrite an earlier application snapshot. Historic contacts can become inactive without losing the relevant history.

## Permissions and confidentiality

- Anonymous requests cannot list/read applications, match candidates, organisation history, notes, payments, or exports, even with a valid public reference.
- A subscriber or editor without grant capabilities is denied admin routes and direct API calls. Menu hiding is not the test — confirm the Editor role specifically has no `grants_*` capability by default, unlike Tree of Light's own Editor grant (see `docs/grants-discovery.md`).
- Reviewer cannot approve or record payment; treasurer cannot approve without the decision capability. A conflict declaration prevents affected reviews/decisions through direct requests too.
- Mutations reject missing/invalid staff nonce, stale row version, and wrong object linkage. A public guest token is not usable as staff authentication.
- Cached pages do not expose data or stale usable guest tokens. Sensitive responses and exports cannot enter a public cache or Media Library.
- Stored HTML is safely escaped in admin, receipt, email, and CSV. Spreadsheet-leading formulas, including whitespace prefixes, cannot execute when CSV is opened.

## Money and history

- For budget £10,000, approval £3,000, and payment £1,000, reports show £7,000 available, £1,000 net paid, and £2,000 outstanding.
- Two concurrent £6,000 approvals against £10,000 cannot both succeed. An over-budget override is possible only if explicitly configured and authorised with reason.
- Approval does not mark paid. Partial payment changes progress; a full payment yields Paid. Repeat payment command records only once; concurrent commands cannot overpay.
- A £200 reversal of the £1,000 payment yields £800 net paid and £2,200 outstanding. Further reversals cannot exceed the original unreversed balance.
- Award reductions below net paid are rejected. Revised/cancelled decisions preserve original records and release only legitimately uncommitted funds.
- Conditions block payment recording until authorised staff mark them fulfilled. Decline and defer do not create an award.
- A 2026-round award paid February 2027 appears in 2026 award history and 2027 payment-date totals. Aggregate queries do not multiply award totals when multiple payments/reviews exist.
- GBP parsing is exact, including £0.01, £100.10, and large permitted values. No float rounding enters records or exports.

## Import, privacy, and operations

- Import dry run changes no records. Reimport of a committed batch does not duplicate awards/payments. Invalid rows have a declared atomic or partial-batch policy and visible outcomes.
- Unknown historical request/date stays unknown. Award-only import is not counted as a submitted application. Organisation matching is confirmed by staff.
- Unticked future contact preference does not prevent application. Withdrawal excludes the active contact from future-round export; historic opt-in does not override withdrawal.
- Verified personal data export covers every relevant table. Erasure/retention dry runs explain removal and any retained financial evidence. Personal details do not survive unintentionally in audit payloads.
- Activation and upgrade on a populated database preserve records; migrations are repeatable. Deactivation and uninstall defaults retain data. Backup restoration is demonstrated — on LocalWP, since no staging environment exists.
- Mail retry jobs work with actual host scheduling — unverified on this host; see `docs/ARCHITECTURE.md`. Staging sends only to controlled inboxes; disabling notifications does not interrupt submission.
- Each sibling plugin remains functional regardless of this plugin's state: Tree of Light's donor form, payment test flow, remembrance output, administration, and reports are unaffected whether Rotary Grants is active or not, and vice versa.

Record runtime versions, commit, test date, and failures in a release checklist (`deployment-notes/RELEASE_CHECKLIST.md`). Unit tests do not replace manual browser/accessibility and coexistence checks on this repository's toolchain.
