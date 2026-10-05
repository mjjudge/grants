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

LocalWP: emails land in the site's Mailpit (Local → site → Tools → Mailpit).
Settings: help email, **two** notification recipients.

- [ ] Settings shows From name "Rotary in the Vale", From address
      `funds@rotaryinthevale.org`, Pause sending unticked; invalid From
      address or a name containing `<` is refused
- [ ] Submit an application → the receipt says a copy will be emailed to the
      applicant's address; within seconds Mailpit has **three** messages:
      one acknowledgement to the applicant and one notice to **each**
      recipient
- [ ] All three are from "Rotary in the Vale <funds@rotaryinthevale.org>"
      with Reply-To the help email
- [ ] Acknowledgement: reference, round, amount, received time, help email;
      says eligibility is not yet assessed; contains none of the free-text
      answers
- [ ] Staff notice: reference, fund, round, organisation, amount and an admin
      link that opens the application (after login); no free-text answers,
      no applicant contact details
- [ ] Rotary Grants → Notifications lists all three as Sent
- [ ] Tick **Pause sending**, submit another application → it is saved and
      the receipt shown; nothing arrives; Notifications shows three Waiting
      and the dashboard says sending is paused. Untick → "Send waiting emails
      now" (or wait ≤5 min) → they arrive
- [ ] Simulate a mail failure (e.g. set the site's SMTP to an unreachable
      host, or temporarily break Mailpit) → application still saved and
      receipt shown; Notifications shows Waiting with a retry time and an
      error code; restore mail → sent on the next run
- [ ] A Failed row (after 5 attempts) appears on the dashboard warning and
      can be re-sent with "Retry now"; the retry appears in the audit log
- [ ] Remove one recipient in Settings, submit again → only the remaining
      recipient is notified; earlier Sent rows for the removed address remain
- [ ] An editor without grants access cannot open Notifications (403)

## Section 5 — Organisation matching and staff applications (G05)

Use clearly fake names (e.g. prefix "TEST") so they are easy to find.

### 5a. Linking and matching

- [ ] Submit two public applications from different organisations using the
      **same** contact email. Each application page says "Not linked"; the
      second does **not** suggest the first organisation because of the
      email. Create a new organisation from each → two separate organisations
- [ ] Submit "The TEST Lunch Club Ltd" after "TEST Lunch Club" exists → the
      application suggests it with reason "same name"; nothing is linked until
      you press "Link to this organisation"
- [ ] A charity number matching an existing organisation is shown as a
      reason; a different charity number lowers the suggestion
- [ ] Linking creates the applicant as a contact of the organisation; linking
      a second application from the same person reuses that contact
- [ ] Changing an existing link requires a reason
- [ ] Two applications from one organisation in the same round show
      "Possible repeat application" on both

### 5b. History is preserved

- [ ] Correct the organisation's name (with a reason) and the contact's email
      → the earlier application's "As submitted" section is unchanged;
      "Correction history" on the organisation shows old → new values and
      the reason
- [ ] Add a new contact and mark the old one "No longer the contact" → the
      old one stays listed as a former contact with dates and can't be edited
- [ ] An applicant who ticked the future-rounds box shows "Yes" with
      "application RG-…" as evidence; withdraw it (with how it was received)
      → shows Withdrawn; re-linking that application does **not** turn it
      back on; recording a new permission needs evidence and keeps the
      history
- [ ] `<prefix>grants_audit_events` rows for these actions contain field
      names and ids only — no names or email addresses

### 5c. Merging

- [ ] Merge a duplicate organisation into another (reason required, confirm
      dialog) → its applications and contacts appear on the kept one; the
      merged one shows "merged into … Reason: …" with no edit form and no
      longer appears in searches or suggestions

### 5d. Staff-entered applications

- [ ] Applications → "Enter a paper/email application": with only
      organisation, contact name, phone, amount and use filled, and a source
      chosen, it saves; the application shows "Entered by staff — Paper
      form", who keyed it in and when
- [ ] Saving with a missing source shows errors and keeps everything typed
- [ ] For a closed round: a date received on the closing day is accepted as
      on time; the day after is flagged **Late** and requires a reason; a date
      before the opening date is also late; a future date is refused
- [ ] "Email the applicant the standard acknowledgement" sends only the
      acknowledgement (check Mailpit); leaving it unticked sends nothing
- [ ] Pressing Save twice (or Back + Save) creates only one application

### 5e. Permissions

- [ ] A user with only `grants_access` can see Organisations and application
      pages but has no link, edit, merge, contact or staff-entry controls,
      and direct POSTs to those actions are refused (403)
- [ ] An editor without grants capabilities is refused all of these pages

## Section 6 — Review and conflicts (G06)

Accounts: two reviewers (`grants_review`), a coordinator
(`grants_manage_organisations`), a decision maker (`grants_decide`) and a
treasurer (`grants_pay` only). Each also needs `grants_access`.

### 6a. Conflict of interest

- [ ] A reviewer opening an application sees "Before you can see the
      committee's reviews and notes…" and no reviews, notes or review form
- [ ] Declaring "no conflict" reveals the committee record and "Add my review"
- [ ] A second reviewer declares a loyalty conflict (description required) →
      sees only the conflict notice: no reviews, notes or forms; trying to
      review by any route is refused
- [ ] The conflicted reviewer cannot change the declaration back to "no
      conflict"; can upgrade it to financial
- [ ] Rotary Grants → Conflicts of Interest: a member can declare against all
      of a round's applications at once; recorded conflicts show "cannot be
      changed"
- [ ] The decision maker sees the round's conflicts register (who, which
      application, what, when, description); a reviewer does not
- [ ] The treasurer sees applications but "Committee reviews and internal
      notes are visible to committee members only"
- [ ] `<prefix>grants_audit_events` `conflict_declared` rows contain the type
      only, not the description

### 6b. Reviews and committee record

- [ ] Add a review with some findings (e.g. Bank account: Unsure — "statement
      not seen") and "Fund in part" £X → shown in the Reviews table;
      application moves to Under review
- [ ] A part amount equal to or above the requested amount is refused
- [ ] Update the review → still one row for that reviewer, marked "version 2"
- [ ] Add an internal note → appears in the committee record; not visible to
      the conflicted reviewer or the treasurer
- [ ] Save a request for more information → shows "Draft — not sent"; nothing
      in Mailpit. Press "Send to applicant" → the applicant receives it (from
      funds@…, Reply-To help email, containing the request text and
      reference, and none of the internal notes); status becomes More
      information requested; it cannot be sent twice
- [ ] Record the applicant's reply with the date received → status returns
      to Under review; the submitted answers are unchanged
- [ ] Coordinator withdraws an application (reason required) → Withdrawn; no
      further reviews possible
- [ ] Mark an application as a duplicate of another by reference → hidden from
      the list unless "Show duplicates" is ticked; banner links to the kept
      one; "Not a duplicate after all" reverses it with a reason

### 6c. Committee list

- [ ] Filters by round, status, amount range and town work together
- [ ] Each row shows the number of reviews and, for committee members, your
      declaration on it
- [ ] An application linked to an organisation lists that organisation's
      previous applications

### 6d. Permissions

- [ ] A reviewer can't withdraw or mark duplicates; an editor without grants
      capabilities is refused every committee action and the Conflicts of
      Interest screen (403)

## Section 7 — Decisions and awards (G07) **[SMOKE]**

Setup: a round with a £10,000 budget; three linked applications (£6,000,
£6,000, £1,000); a decision maker (`grants_decide`) who has declared no
conflict on each.

- [ ] Approve the first at £6,000 with a "before payment" condition → award
      shown; status Decided; budget box shows £6,000 approved, £4,000
      available
- [ ] Approve the second at £6,000 → **warning**: "£2,000 over its budget
      (£4,000 is available)"; everything typed is still in the form; no award
      created
- [ ] Tick the confirmation **without** a note → still refused; add a note
      ("£2,000 from the club charity account, agreed …") → recorded; the
      award, the Awards screen and the round edit screen all show "over budget
      by £2,000" with the note
- [ ] Approve the third at £500 (a part award) → warned again (the round is
      already over), recorded only with a note
- [ ] Decline an application → no award; Decided. Defer one → back to Under
      review, no award
- [ ] A decided application shows "Reopen this decision" (reason required);
      reopening keeps the award; a revised approval changes the same award
      and the decision history shows both decisions, the first superseded
- [ ] Revising after reopening to "Decline" cancels the award (nothing paid
      yet) and frees its amount in the budget
- [ ] Try to lower the round budget below what's approved → refused; raising
      it works
- [ ] Mark a condition as met with evidence → shown with who/when; the Awards
      screen's "outstanding" count drops
- [ ] "Prepare the decision notice": suggested wording has the amount and
      conditions and a "don't email bank details" line, and **not** the
      internal reason or funding note; save draft → nothing sent; "Send to
      applicant" → email arrives (Mailpit), can't be sent twice
- [ ] Two people approving different £6,000 applications at the same moment
      against £10,000: only one succeeds without the over-budget confirmation
      (verified by script — see commit notes)
- [ ] A reviewer sees decisions and the award but no decision form; a member
      with a declared conflict can't decide; an editor is refused (403)

## Section 8 — Payment ledger (G08) **[SMOKE]**

Setup: a round with a £10,000 budget; an approved £3,000 award with one
"before payment" condition; a treasurer account (`grants_pay`).

- [ ] Rotary Grants → Payments lists the award as Unpaid with "1 condition to
      meet first"; its award page has no payment form yet
- [ ] Mark the condition met (application page) → the "Record a payment" form
      appears; it has **no** bank account / sort code fields
- [ ] Try £5,000 → "more than the £3,000.00 still outstanding"; amount kept
- [ ] Record £1,000 by bank transfer with a reference → ledger shows it; Part
      paid; outstanding £2,000. The round's available-to-award is **unchanged**
      (docs/06: budget £10,000, approval £3,000, payment £1,000 → £7,000
      available, £1,000 net paid, £2,000 outstanding)
- [ ] Press Back and submit the same form again → "already been saved",
      still one payment
- [ ] Reverse £200 of it with a reason, leaving "money was returned"
      unticked → shown as "Ledger correction only"; net paid £800,
      outstanding £2,200, approval still £3,000
- [ ] A reversal larger than the payment's remaining £800 is refused
- [ ] Pay the remaining £2,200 by cheque → Paid; no further payment possible
- [ ] Reopen the application and try to approve £2,500 → refused ("cannot be
      less than the £3,000.00 already paid"); try to decline → refused
- [ ] A cancelled award can't be paid
- [ ] A decision maker or reviewer sees the ledger but no forms; an editor is
      refused the Payments screen (403)

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
