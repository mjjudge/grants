# Architecture — Rotary Grants

## System overview

```
┌─────────────────────────────────────────────────────────────┐
│                    rotaryinthevale.org                       │
│                     (SiteGround / WP)                        │
│                                                               │
│  Public pages (slugs TBD at G01/G03)   Admin plugin pages     │
│  ────────────────────────────────      ──────────────────    │
│  Apply for funding (per-round form)    Applications          │
│  (success/cancel via query string,     Organisations         │
│   not separate slugs — see Tree of     Funding rounds        │
│   Light's own donate-page pattern)     Awards and payments   │
│                                         Reports               │
│                                         Settings              │
│                                                               │
│  ┌──────────────────────────────────────────────────────┐    │
│  │              Rotary Grants WordPress Plugin           │    │
│  │  Admin │ Public │ Database │ Services │ Mail │ Audit  │    │
│  └──────────────────────────────────────────────────────┘    │
│                                                               │
│  ┌──────────────────────────────────┐                        │
│  │  SiteGround MySQL database       │  ← master record       │
│  │  <prefix>grants_rounds           │                        │
│  │  <prefix>grants_organisations    │                        │
│  │  <prefix>grants_contacts         │                        │
│  │  <prefix>grants_preferences      │                        │
│  │  <prefix>grants_applications     │                        │
│  │  <prefix>grants_submissions      │                        │
│  │  <prefix>grants_reviews          │                        │
│  │  <prefix>grants_decisions        │                        │
│  │  <prefix>grants_awards           │                        │
│  │  <prefix>grants_payments         │                        │
│  │  <prefix>grants_audit_events     │                        │
│  │  <prefix>grants_notifications    │                        │
│  │  <prefix>grants_import_batches   │                        │
│  │  <prefix>grants_settings         │                        │
│  └──────────────────────────────────┘                        │
└─────────────────────────────────────────────────────────────┘
         │
         ▼
   ┌─────────────┐
   │ SiteGround  │
   │   mail      │   (no Stripe, no payment gateway — this plugin never moves money)
   └─────────────┘
```

Table names above use `<prefix>` because the real WordPress table prefix on
this host is non-default (confirmed in Tree of Light's own
`docs/ARCHITECTURE.md`: `phw_`, set at install time) — the actual table name is
`$wpdb->prefix . 'grants_rounds'` etc., not a literal `wp_grants_rounds`.

## Application → decision → payment flow

```
Public form submission
    │
    ├──[idempotency key reused, same payload]──▶ same receipt, no new row
    ├──[idempotency key reused, different payload]──▶ rejected
    │
    ▼
grants_applications (status: received) + grants_submissions row
    │
    ├──▶ applicant acknowledgement queued (grants_notifications)
    └──▶ staff notification queued to configured recipients (grants_notifications)
    │
    ▼
Staff review (grants_reviews) ── eligibility findings, recommendation, conflict check
    │
    ▼
Decision (grants_decisions: approve / decline / defer)
    │
    ├──[approve]──▶ grants_awards row created/revised, round budget reserved
    ├──[decline]──▶ reason recorded, no award
    └──[defer]────▶ back to review, no award
    │
    ▼ (approved only)
Treasurer records payment outside WordPress, then logs it
    │
    ▼
grants_payments (entry_type: payment) ── reduces outstanding, never re-reserves budget
    │
    └──[correction needed]──▶ grants_payments (entry_type: reversal), original entry untouched
```

## External services

| Service | Purpose | Credentials location |
|---|---|---|
| SiteGround mail | Transactional email (acknowledgement, staff notification, decision message) | Plugin Settings screen, not hardcoded |

No payment gateway. No Stripe. This plugin records payments made elsewhere; it
never initiates one. If that ever changes, it is a new, separately-reviewed
capability — see `CLAUDE.md`.

SiteGround is the **master system of record**.

## WordPress hosting constraints

Carried over from Tree of Light's own `docs/ARCHITECTURE.md`, since this plugin
runs on the same install, with the provisional caveat preserved rather than
treated as independently re-confirmed:

- PHP version: confirm with the SiteGround dashboard before relying on it —
  8.2 is the declared target, not independently verified from this planning
  session.
- No shell access for deployment — file transfer via SFTP or the WordPress
  admin, same as the sibling plugins.
- No custom Apache/Nginx config (shared hosting).
- WordPress database prefix on this host is non-default (`phw_`, confirmed in
  Tree of Light's own architecture doc) — this plugin's own tables sit under
  `$wpdb->prefix . 'grants_'`, same mechanism, different suffix.
- Cron: `wp_cron` is the only option (no system cron confirmed available).
  **Unverified whether it runs reliably for a low-traffic period** — no sibling
  plugin currently relies on it to check against; this needs a direct test on
  the real host before the notification retry queue depends on it, not an
  assumption.
- **No staging environment exists** (SiteGround single-site plan, confirmed via
  Tree of Light's own `deployment-notes/LOCAL_DEV_SETUP.md`). LocalWP is the
  only pre-production environment. Deploy directly to production, backed by a
  SiteGround snapshot backup taken first — see
  `deployment-notes/DEPLOY_TO_PRODUCTION.md`.

## Dependency policy

No Composer, no npm build step, no third-party PHP library, unless a specific
task's need is explained in its own commit/PR and the maintenance cost is
accepted explicitly — matching Tree of Light's own zero-dependency convention,
confirmed by inspection (no `composer.json`/`package.json` anywhere in that
repository either). Vanilla JS or jQuery only on the frontend.

## Testing

No automated test framework, matching every sibling plugin. See `CLAUDE.md`'s
Testing discipline section for what actually substitutes for one here:
`php -l`, throwaway CLI verification scripts for real logic, and the manual
walkthrough in `test-notes/TEST_PLAN.md`.
