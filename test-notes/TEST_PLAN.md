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

## Section 1 — Plugin activation, access and settings (G01) **[SMOKE]**

### 1a. Activation and coexistence

- [ ] With Tree of Light **inactive** (or on a site without it), activate
      Rotary Grants — no errors; a **Rotary Grants** menu (awards icon) appears
      with Dashboard, Settings and Access
- [ ] Tables `<prefix>grants_audit_events` and `<prefix>grants_settings` exist;
      option `grants_db_version` = current version
- [ ] Deactivate and reactivate — no error, no duplicate tables, settings and
      per-user access grants still present afterwards
- [ ] With Tree of Light **active** alongside, run Tree of Light's own `[SMOKE]`
      sections and confirm no change in its behaviour; both menus appear
      independently
- [ ] Deactivate Tree of Light with Rotary Grants still active — Rotary Grants
      menu, Dashboard and Settings still load; deactivate Rotary Grants — Tree
      of Light still loads
- [ ] `grants-admin.css` loads on Rotary Grants pages only (view source on a
      Tree of Light page: not present)

### 1b. Capabilities and access

- [ ] Users → Roles (or a role-inspection plugin): Administrator holds all
      eleven `grants_*` capabilities; **Editor holds none**; no other role
      holds any
- [ ] Log in as an **editor** with no grants access: no Rotary Grants menu;
      visiting `wp-admin/admin.php?page=grants-dashboard`, `…page=grants-settings`
      and `…page=grants-access` directly each gives "Sorry, you are not allowed"
      (403)
- [ ] Same three URLs as a **subscriber** → 403
- [ ] As administrator, edit a test subscriber's profile → **Rotary Grants
      access** section is shown; tick only "Add reviews and recommendations",
      save → reopen the profile: both that and the "Access" capability are
      ticked (access is implied)
- [ ] Rotary Grants → Access lists that user with `grants_access` and
      `grants_review`; administrators listed "(via role)"
- [ ] Log in as that subscriber: Rotary Grants → Dashboard loads; Settings and
      Access are not in the menu and give 403 by direct URL; their own profile
      page does **not** show the Rotary Grants access section
- [ ] As administrator, untick everything for that user and save → user
      disappears from the Access screen; Dashboard URL gives them 403
- [ ] `<prefix>grants_audit_events` has `access_changed` rows for the grant and
      revoke, with the capability names only

### 1c. Settings

- [ ] Rotary Grants → Settings shows Help/contact email and New-application
      recipients; Dashboard shows both as "Not set / None" until configured
- [ ] Enter `not-an-email` as help email and `a@example.org, bogus` as
      recipients → not saved; error summary at top (focused), field errors
      beside each field, typed values still in the fields
- [ ] Enter a valid help email and three recipients mixing commas, new lines
      and a case-different duplicate → "Settings saved"; reload shows one
      address per line, duplicate removed; Dashboard shows "Set" and
      "3 recipients"
- [ ] 21 recipients → rejected with "no more than 20"
- [ ] Clearing the help email is allowed (it is only required to open a round)
- [ ] `<prefix>grants_audit_events` has a `settings_updated` row naming the
      changed keys and recipient count — **no email addresses in the summary**
- [ ] Saving again without changes writes no new audit row

## Section 2 — Funding round setup (G02)

Set Settings → General → Timezone to **London** first.

### 2a. Creating and editing

- [ ] Rotary Grants → Funding Rounds → Add Round. With an earlier round
      present, the fund name and wording are pre-filled and a notice says
      where they were copied from
- [ ] Submit with empty label/fund, year `26`, budget `1.005`, maximum award
      larger than the budget → not saved; error summary (focused) plus
      field errors; everything typed is still in the form
- [ ] Budget accepts `10000`, `10,000`, `£10,000.50`; rejects `-5`, `1e3`,
      `1,00`, `10.001` — reload shows the exact amount (no rounding)
- [ ] Opening `2027-03-28 01:30` is rejected (clocks go forward); closing
      `2026-10-25 01:30` is rejected (happens twice); `2026-10-25 02:00` is
      accepted
- [ ] A round created in summer at 09:00 redisplays as 09:00 (not 08:00 or
      10:00) after saving; the list shows the same times
- [ ] Wording with `<script>` is saved without the script; paragraphs, lists
      and links survive
- [ ] Changing any wording increases "Wording version"; changing only the
      budget does not
- [ ] Open the same round in two tabs, save in one, then save in the other →
      "Someone else changed this round"; nothing overwritten
- [ ] Setting the site timezone to "UTC+0" shows the fixed-offset warning on
      the round screen (set it back to London afterwards)

### 2b. Opening, closing and archiving

- [ ] A new draft lists every missing item under "Before this round can
      open", including Settings items (help email, recipients, privacy notice);
      no Open button until all are resolved
- [ ] With everything set and opening time in the future → Open → status
      "Open — not yet accepting applications"; once the opening time passes,
      "accepting applications"
- [ ] A second round for the **same fund** (any capitalisation) cannot open
      while the first is open; a round for a **different fund** can
- [ ] While open: blanking the eligibility wording is refused; moving the
      closing time into the past is allowed and shows "deadline passed"
- [ ] Close → Reopen is refused while the closing time is in the past; extend
      the closing time, then Reopen works
- [ ] Archive a closed round → it is read-only (no Save, no status buttons)
- [ ] `<prefix>grants_audit_events` has `round_created`, `round_updated`
      (changed field names, budget old/new) and `round_status_changed` rows

### 2c. Permissions and persistence

- [ ] A user with only `grants_access` sees the round list without edit links
      or an Add Round button; the add/edit URLs show "Access denied."
- [ ] An editor without grants capabilities is denied the round list by
      direct URL
- [ ] Deactivate and reactivate the plugin — all rounds still present and
      unchanged

## Section 3 — Application form (G03) **[SMOKE]**

Setup: Settings complete; two rounds open for two different funds, one with
publicity and presentation wording and a maximum award, one without. Pages:
`[rotary_grant_application fund="<fund A>"]` and
`[rotary_grant_application fund="<fund B>"]`. Use a private browser window.

### 3a. What the applicant sees

- [ ] Each page shows "Apply for <its fund> funding", its own round label,
      closing date, help email, intro, eligibility and exclusions — never the
      other fund's text
- [ ] Publicity/presentation sections (and their tick boxes) appear only on
      the round that has that wording; the maximum award is mentioned only
      where one is set
- [ ] A community group with no charity number can submit
- [ ] The page's response headers include `Cache-Control: no-cache…` and set a
      `grants_form_session` cookie (browser dev tools → Network)
- [ ] A page with plain `[rotary_grant_application]` while two rounds are open
      shows "not currently open" to the public and a "Note for site editors"
      when logged in

### 3b. Validation and accessibility

- [ ] Submit an empty form → error summary at the top receives focus; each
      message links to its field; every field error appears beside the field
- [ ] Enter `Zoë O'Brien`, `St Mary's Église & Friends`, `5 < 10 chairs` —
      these are accepted and display exactly as typed after an error elsewhere
- [ ] `<script>alert(1)</script>` in a text box → "remove the HTML" error;
      `javascript:alert(1)` as website → rejected
- [ ] Amount: `1.005`, `-5`, `0` rejected; `0.01` and `1,250.50` accepted;
      above the round's maximum award rejected
- [ ] "Locally led? No", "Local branch? Yes", "One-off? Not sure" each require
      their explanation
- [ ] After any error, every answer (including ticked boxes and radio choices)
      is still filled in
- [ ] Whole form can be completed with keyboard only; labels read correctly
      with a screen reader (VoiceOver/NVDA spot check); usable on a phone

### 3c. Submission

- [ ] A valid submission shows "Application received" with a reference like
      `RG-7KQ2-M9XD`, the organisation, round, amount and time — and appears
      under Rotary Grants → Applications with the full answers and versions
- [ ] Refresh the receipt page → same receipt; open the receipt URL in a
      different browser → generic "Thank you" only, no details
- [ ] Double-click Submit (or resubmit via Back + Submit with the same
      answers) → still exactly one application, same reference
- [ ] Back, change the amount, Submit again on the same form → refused
      ("already been used to submit application RG-…"); reload the page to
      send a genuinely new application → a second application is created
- [ ] Load the form, then close the round (or move its closing time into the
      past) in admin, then submit → refused as not accepting; nothing saved
- [ ] Leave the form open for over a day, then submit → "needed refreshing"
      with answers kept; submitting again works
- [ ] `<prefix>grants_audit_events` has `application_submitted` with the
      reference and round only — no names or email addresses

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
