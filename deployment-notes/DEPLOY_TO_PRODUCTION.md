# Deploying to Production — Rotary Grants

There is no staging environment. All development and testing happens on
LocalWP. Production deployments follow this checklist every time, no
exceptions — same discipline as Tree of Light, Duck Race, and Battle Shield
Sponsorship on this same SiteGround account.

---

## Before you touch anything on production

1. **Take a manual backup** — SiteGround Site Tools → Security → Backups →
   Create Backup. Wait for it to complete and confirm it appears with today's
   date/time. Do not proceed until the backup exists.
2. **Check the site is working** — visit the live site first and note anything
   already broken, including whether Tree of Light (or any other sibling
   plugin) is currently functioning normally, so a later regression can be
   attributed correctly.
3. **Avoid committee/event activity windows** — deploy when a live funding
   round isn't actively accepting submissions if at all possible.

## Building the release ZIP

1. Bump `Version:` in `plugin/rotary-grants.php`'s header **and** the
   `GRANTS_VERSION` constant to match.
2. Add a line to the Version history table in `README.md` summarising what
   changed.
3. Run `scripts/build-zip` — produces `dist/rotary-grants-<version>.zip`.
4. Verify the zip's actual contents (`unzip -l dist/rotary-grants-<version>.zip`)
   include every new/changed file before trusting it.
5. Commit `dist/rotary-grants-<version>.zip` alongside the code changes — it is
   not gitignored, same convention as the sibling plugins.

## If this release includes a database migration

**A deactivate/reactivate cycle on the live site is required to run it.**
Confirmed from Tree of Light's own codebase: its migrator only ever runs from
the activation hook, never on an ordinary file update, and Rotary Grants'
migrator is built the same way deliberately (see `CLAUDE.md`). Say this
explicitly in the deployment notes for that specific release — don't assume the
person deploying remembers it from a previous release.

## Installing the build

Upload via WP Admin → Plugins → Add New → Upload Plugin (or SFTP to
`wp-content/plugins/rotary-grants/` if preferred) — same process as the other
Rotary plugins on this account. Deactivate any existing version first if
upgrading via file replacement rather than the WordPress upgrade flow, to avoid
a partially-overwritten plugin directory.

## After deploying

1. Confirm the plugin is active and its admin menu loads without errors.
2. If a migration ran, confirm it via the plugin's own dashboard/db-version
   indicator (build one in G01/G02 — don't skip it just because it's not
   user-facing).
3. Confirm Tree of Light (and any other active sibling plugin) still works
   normally — donor form, a test-mode submission, admin pages.
4. Walk the relevant `[SMOKE]` sections of `test-notes/TEST_PLAN.md`.

## Rollback

Rolling back plugin files does not roll back database changes. If a migration
has already run, restoring old files against new tables may not be safe —
either confirm backward compatibility or restore the full SiteGround backup
taken in step 1, accepting the loss of anything received since it was taken.
