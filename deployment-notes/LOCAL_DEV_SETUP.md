# Local Developer Setup — Rotary Grants

## Production environment reference

Same SiteGround account/site as the other Rotary in the Vale plugins
(Tree of Light, Duck Race, Battle Shield Sponsorship) — confirm the exact PHP
version, database name, WordPress table prefix, and server details from the
SiteGround dashboard or Tree of Light's own
`~/myapps/TOL/deployment-notes/LOCAL_DEV_SETUP.md` before deploying; do not
assume they're unchanged without checking. Declared targets, provisional until
confirmed (see `docs/ARCHITECTURE.md`):

| Item | Value |
|---|---|
| Hosting | SiteGround (shared/managed) |
| Site | rotaryinthevale.org |
| PHP version | 8.2.x (provisional) |
| Database | MySQL (InnoDB, `utf8mb4_unicode_520_ci`) |
| WP table prefix | non-default (confirm actual value — Tree of Light's own is `phw_`) |
| Plugin table prefix | `$wpdb->prefix . 'grants_'` |

## Staging environment

**There is no staging environment** — single-site SiteGround plan, confirmed
from Tree of Light's own setup notes. LocalWP is the test environment. All
development and testing happens locally before deploying directly to
production, backed by a SiteGround backup taken first — see
`DEPLOY_TO_PRODUCTION.md`.

## LocalWP setup

1. Download and install [LocalWP](https://localwp.com) if not already set up
   for the other Rotary plugins.
2. Create a new site (e.g. `grants-dev`), or reuse the same local site already
   used for Tree of Light development if one exists — this plugin is designed
   to coexist, and testing coexistence is part of `backlog/BACKLOG.md` (G01,
   G12).
3. Set the PHP version to match the confirmed production value.
4. If reusing an existing local site, set the table prefix to match the real
   WordPress table prefix (not `wp_` by default).
5. Copy `plugin/` into `wp-content/plugins/rotary-grants/`, or build and
   install the zip via `scripts/build-zip` + the WordPress admin's "Upload
   Plugin" screen, matching how the other sibling plugins are installed
   locally.
6. Activate Rotary Grants. Optionally also activate Tree of Light to exercise
   the coexistence checks in `test-notes/TEST_PLAN.md`.

## Mail testing

Use a controlled test inbox (see Tree of Light's own mail-testing setup for the
established pattern on this host) — never send a real notification or
acknowledgement to a real address during development. See `CLAUDE.md`'s note on
WP-Cron reliability being unverified before relying on it for the notification
retry queue.
