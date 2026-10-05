# Uninstall, data retention and full erasure — Rotary Grants

## What happens by default

| Action | Effect on data |
|---|---|
| Deactivate | Nothing removed. Scheduled jobs (email retries, daily housekeeping) stop. |
| Delete / uninstall from Plugins | Nothing removed except the scheduled jobs (`uninstall.php`). Tables, settings and capabilities stay, so reinstalling the same version restores everything. |
| Retention (Rotary Grants → Privacy & Retention → Apply) | Personal details and free-text answers anonymised in records past the periods set in Settings. Awards, payments, decisions and amounts kept. |
| WordPress "Erase Personal Data" for an email | That person's contact details and free-text answers anonymised (applications still being considered are kept until decided). Financial records kept. |

## Backups and logs (docs/04)

- **SiteGround backups** keep copies of the database for SiteGround's own
  retention window (check Site Tools → Security → Backups). Anonymising data
  on the live site does not remove it from existing backups; it falls out as
  those backups expire. Say so in the privacy notice; don't promise immediate
  deletion from every backup.
- **Audit log** (`<prefix>grants_audit_events`) stores who did what, with
  ids, field names, counts and amounts — by design never names, email
  addresses or free text — so it doesn't need anonymising.
- **Old personal values** from corrections live only in
  `<prefix>grants_amendments` and are anonymised with the contact.
- **Email log** recipients are anonymised by the "Email log recipients"
  retention category.
- **Transients**: form-state and receipt transients expire within 10–30
  minutes; rate-limit entries within an hour (salted IP hash only).

## Removing everything (only with authorisation)

Do this only when the club has formally decided to stop using the plugin
**and** confirmed no financial record still needs to be kept.

1. Take a SiteGround backup and download a copy. Record who authorised the
   removal and when.
2. Export the reports you must keep (Reports → each CSV).
3. Deactivate and delete the plugin.
4. In phpMyAdmin (Site Tools → Site → MySQL → phpMyAdmin), with the real
   table prefix (`phw_` on the live site — confirm first):

   ```sql
   DROP TABLE phw_grants_payments, phw_grants_award_conditions, phw_grants_awards,
              phw_grants_decisions, phw_grants_reviews, phw_grants_conflicts,
              phw_grants_application_notes, phw_grants_notifications,
              phw_grants_submissions, phw_grants_applications,
              phw_grants_preferences, phw_grants_amendments, phw_grants_contacts,
              phw_grants_organisations, phw_grants_rounds, phw_grants_settings,
              phw_grants_audit_events;
   DELETE FROM phw_options WHERE option_name = 'grants_db_version'
       OR option_name LIKE '\_transient\_%grants\_%' OR option_name LIKE '\_transient\_timeout\_%grants\_%';
   ```
5. Remove the `grants_*` capabilities: Rotary Grants → Access lists who held
   them; with the plugin still active beforehand, untick each person, or run
   `wp cap remove administrator grants_access …` with WP-CLI.
