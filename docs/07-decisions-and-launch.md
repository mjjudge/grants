# Committee decisions and launch

Adapted from the original build pack — two rows added (naming, settings/
notifications) that are now already decided rather than open; everything else
in the "Confirm before launch" column is still genuinely open and must not be
guessed.

## Proposed defaults and outstanding decisions

| Topic | Proposed default | Confirm before launch |
|---|---|---|
| Implementation | Independent companion plugin, own repository (`~/myapps/grants`) | **Decided** — see `backlog/DECISIONS.md` |
| Naming | Plugin "Rotary Grants", slug `rotary-grants`, not Tree-of-Light-specific | **Decided** — see `backlog/DECISIONS.md`; confirm final public-facing wording for the application page per fund |
| Settings and staff notification | A Settings screen with a configurable notification recipient list, separate from the applicant acknowledgement | **Decided to build** — see `backlog/DECISIONS.md`; confirm the actual initial recipient addresses before a live round opens |
| Applicants | Organisations and eligible community groups; no login | Eligibility wording from source remains current, per fund |
| Round identity | Fund name + year label, with separate actual payment dates | Award meeting/payment year per fund |
| Reporting year | **Built (DEC-016):** global setting, default calendar year (January) | Confirm whether the treasurer wants the Rotary year (July) or the charity's accounting year instead — one Settings change |
| Budget | Manually set distributable budget, per round. **Over-budget policy decided (DEC-014):** allowed with a warning and a mandatory note of where the extra money comes from | Amount and authority to amend |
| Deadline | Configured date/time in WordPress timezone | Open/close dates and late-application handling |
| Award limits | No invented cap | Whether a cap/minimum is wanted, per fund |
| Repeat applicants | Allowed; previous history visible | Repeat-award rules and multiple applications per round |
| Contact | Named organisation representative | Help inbox and ownership. **Notification sender decided:** `funds@rotaryinthevale.org` / "Rotary in the Vale" — **must be entered in Settings** (the plugin's built-in defaults are generic: site name / WordPress sender); Reply-To = help email — see `backlog/DECISIONS.md` DEC-011 |
| Publicity | Preserve current undertaking visibly, per fund | Exceptions, photo handling, and safeguarding |
| Future-round email | Separate optional unticked preference | Approved wording, withdrawal route, and responsible entity |
| Bank details | Treasurer verifies outside WordPress | Existing verification/payment process |
| Evidence | Declarations then staff verification outside form | Whether governing documents/accounts need later uploads |
| Privacy | **Built (DEC-018):** retention periods in Settings with suggested values (24 / 84 / 36 / 12 months), dry run + Apply, WordPress export/erase tools, anonymise-not-delete | Controller, purposes/lawful bases, the privacy notice text, **confirming the periods** (tick in Settings), and who holds `grants_privacy` |
| Access | Named accounts and custom capabilities, never auto-granted to Editor | Committee roles and decision authority |
| Conflicts of interest | **Built (DEC-013):** declare before taking part; any declared conflict blocks reviewing/deciding and hides the discussion; no overrides — based on Rotary International's grants COI policy (TRF Code of Policies §30.040) and Charity Commission CC29 | The club has no COI policy of its own: the committee should **adopt one** (the built behaviour is a ready-made starting point) and record it in its minutes |
| Rotarian / family eligibility | Not restricted | RI bars current Rotarians, their families, club employees and anyone who left Rotary in the last 3 years from benefiting from *Foundation* grants. Decide whether anything similar applies to the club's own funds — not built |
| Multi-fund model | A `fund_name` text field on each round; no separate Fund entity yet | Whether a fuller model (per-fund settings/eligibility/branding) is ever needed — add only if a specific fund's rules can't be expressed as a label |
| Automated testing | None, matching the sibling plugins; manual TEST_PLAN.md + throwaway verification scripts | Revisit explicitly if a task's risk genuinely justifies the maintenance cost of a real framework — do not add one as a side effect |

These decisions do not prevent implementing the schema and form on a local
install. They prevent opening an inadequately configured live funding round.

## Historical import

**Deferred (DEC-017):** this club has no historical grant records, so no
importer is built for the first release. The design below is kept for any
future need.

Collect previous recipient lists from committee or treasurer records; none were
supplied with this request. Proposed CSV columns: source_record_id,
organisation_name, charity_number, town, round_label, fund_name, campaign_year,
requested_amount_gbp nullable, approved_amount_gbp nullable, approval_date
nullable, paid_amount_gbp nullable, payment_date nullable, payment_reference
nullable, contact_name/email/role nullable, source_note.

One award may have multiple payment rows, linked by a source award ID. Define
that extension before importing split payments. Do not infer paid from
approved. Do not infer permission to contact from the existence of an email
address. Do not infer an application from a payment record.

Import process: choose round mapping; upload locally through an authorised
admin route; validate values; preview possible organisation matches; show row
errors and totals; confirm links; execute a transaction or clearly declared
batch policy; reconcile totals with source; preserve a batch ID and source
references. Reimport uses stable source IDs/fingerprint plus explicit
resolution, never email alone.

## Launch sequence

1. Review `docs/grants-discovery.md` and `backlog/DECISIONS.md`; agree the plugin
   boundary (already done) and any remaining open decision above. Build on a
   feature branch in this repository and a local WordPress instance (LocalWP —
   **there is no staging environment for this host**, see
   `docs/ARCHITECTURE.md`; this is a correction to the original brief's assumed
   staging step, not a gap to fill in later).
2. Configure a test round and synthetic data. Keep real applicants and
   notifications out of development.
3. Complete the acceptance checks in `docs/06-acceptance-tests.md` and the
   coexistence checks against the running Tree of Light (and other sibling)
   plugins. Reconcile reports manually against a known fixture.
4. Confirm the committee choices above, the privacy notice, the help route, the
   notification recipient list, and actual mail delivery. Train coordinator and
   treasurer using a synthetic application through approval and payment.
5. Take a database/file backup and rehearse restoration (SiteGround backup, same
   as Tree of Light's own `deployment-notes/RELEASE_CHECKLIST.md`). Package the
   plugin with `scripts/build-zip` and record the schema version. Do not deploy
   by overwriting the Tree of Light plugin directory, or any other sibling
   plugin's directory.
6. Install on production through the authorised release process. Confirm
   pages, permissions, cache configuration (see
   `deployment-notes/CACHE_EXCLUSIONS.md`), and mail before opening the round.
7. Open the round and run a controlled application check. Remove or clearly
   mark test data through an audited process. Review notification failures and
   pending organisation matches during the first week.

If release fails, disable Rotary Grants to stop submissions and restore service
deliberately. Keep committed applications safe. Rolling back files does not
roll back database changes; determine whether the new schema is backward
compatible, or restore the backup with a plan for data received since it was
taken.
