# End-to-End Test Plan — Rotary Grants

**Environment:** LocalWP — there is no staging environment for this host (see
`docs/ARCHITECTURE.md`). Use synthetic organisations, staff accounts, and a
controlled test mail inbox; never a real applicant.
**No automated test framework exists** — this manual plan, plus throwaway CLI
verification scripts for real logic, is the actual testing discipline for this
repository. See `CLAUDE.md`.

---

## How to use this plan

Work through each section in order as its backlog task (`backlog/BACKLOG.md`)
ships. Mark each check `[x]` when it passes, or note `[FAIL]` with a brief
description. Run the full plan before any production deployment. This file is
a skeleton seeded from `docs/06-acceptance-tests.md` — expand each section with
concrete steps once the corresponding screen/behaviour actually exists; don't
leave a section looking "done" with checkboxes that were never really
exercised.

For a quick smoke test after a patch deployment, run only the sections marked
**[SMOKE]**.

---

## Section 1 — Plugin activation and coexistence (G01) **[SMOKE]**

- [ ] Plugin activates without errors with Tree of Light **inactive**
- [ ] Rotary Grants top-level admin menu appears, gated on `grants_access`
- [ ] Plugin activates and functions correctly with Tree of Light **active**
      alongside it — run Tree of Light's own `[SMOKE]` sections and confirm no
      change in its behaviour
- [ ] Deactivating Tree of Light does not break Rotary Grants; deactivating
      Rotary Grants does not break Tree of Light
- [ ] A user with only `subscriber`/`editor` WordPress roles (no `grants_*`
      capability) is denied the admin menu **and** denied by direct URL/request
      to any admin-post/REST route this plugin registers
- [ ] Settings screen exists; help-email and notification-recipient fields save
      and reload correctly; capability-gated

## Section 2 — Funding round setup (G02)

- [ ] A round can be created with a `fund_name`, label, open/close dates, and
      budget
- [ ] Opening a round is blocked until help email and notification recipients
      are configured
- [ ] Dates entered in site timezone store correctly in UTC and redisplay
      correctly
- [ ] Re-running activation/upgrade on a populated database does not duplicate
      rows or error

## Section 3 — Application form (G03) **[SMOKE]**

- [ ] Every original eligibility rule and exclusion from the source form is
      visible for the active round's fund
- [ ] A community group with no charity number can submit
- [ ] Required/invalid fields show accessible errors beside the field and in a
      focusable summary; entered answers survive a validation failure
      (file inputs, if any, excepted — browsers never repopulate those)
- [ ] Two concurrent submissions with the same idempotency key produce one
      application and the same reference; a different payload with the same
      key is rejected
- [ ] A previously loaded form cannot submit after the round's closing time
      (server time, not client clock)
- [ ] Script payloads, unsafe URLs, malformed/negative money, more than two
      decimal places, and forged staff-only fields are all rejected

## Section 4 — Receipt and notifications (G04) **[SMOKE]**

- [ ] Successful submission shows a receipt only after the application has
      actually committed to the database
- [ ] Applicant acknowledgement is queued/sent with reference, round, amount,
      and help route — no internal notes or unrelated history
- [ ] **Every address in the configured notification-recipient list receives
      the new-application staff notification**, distinct from the applicant
      email, with no full copy of submitted answers
- [ ] A mail failure (simulate via a broken test inbox) does not lose or
      duplicate the saved application, and does not show as an error to the
      applicant

## Section 5 — Organisation matching and staff applications (G05)

- [ ] Two organisations sharing one contact email remain distinct records
- [ ] Similar organisation names surface as staff match candidates, never an
      automatic merge
- [ ] A staff merge preserves both organisations' application history and
      records a reason
- [ ] Editing a contact's current details does not alter a previously
      submitted application's snapshot

## Section 6 — Review and conflicts (G06)

- [ ] Applications filter correctly by round, status, amount, locality
- [ ] A reviewer with a declared conflict is blocked from reviewing/deciding
      that application via direct request, not just UI hiding
- [ ] Internal notes are never visible to any unauthenticated or applicant-facing view

## Section 7 — Decisions and awards (G07) **[SMOKE]**

- [ ] Approve/decline/defer all record correctly; only approve creates an award
- [ ] Two concurrent approvals that would together exceed the round budget —
      only one succeeds
- [ ] An award cannot be reduced below amounts already paid against it

## Section 8 — Payment ledger (G08) **[SMOKE]**

- [ ] Recording a payment above outstanding approval is rejected
- [ ] Budget example from `docs/02-architecture-and-data.md`: £10,000 budget,
      £3,000 approved, £1,000 paid → £7,000 available, £2,000 outstanding
- [ ] A £200 reversal of that £1,000 payment → £800 net paid, £2,200 outstanding
- [ ] A reversal cannot exceed its original payment's unreversed amount
- [ ] Repeating the same payment command does not record it twice

## Section 9 — Reports and exports (G09)

- [ ] Round overview, organisation history, committee shortlist, treasurer
      report, and future-round contact list all reconcile against a known
      fixture
- [ ] CSV exports escape spreadsheet-leading formula characters
      (`=`, `+`, `-`, `@`, leading tab/whitespace)
- [ ] A withdrawn contact preference is excluded from the future-round export

## Section 10 — Historical import (G10)

- [ ] Dry run changes no records
- [ ] Reimporting a committed batch does not duplicate awards/payments
- [ ] Unknown historical amount/date is stored as unknown, not zero or invented

## Section 11 — Privacy and retention (G11)

- [ ] WordPress personal-data export/erase tools cover every relevant table
- [ ] A retention dry run explains what would be removed/anonymised and what
      financial evidence would be retained and why

## Section 12 — Pre-launch final checks (G12) **[SMOKE]**

- [ ] Full acceptance-test pass against `docs/06-acceptance-tests.md`
- [ ] Database/file backup taken and restoration rehearsed (SiteGround backup,
      LocalWP restore — no staging environment exists to rehearse on instead)
- [ ] Coordinator and treasurer have walked through a synthetic application end
      to end (submit → review → decide → pay)
- [ ] Committee and treasurer sign-off recorded
