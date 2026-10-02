# Release Checklist — Rotary Grants

Run through this before every production release, in addition to
`DEPLOY_TO_PRODUCTION.md`'s steps. Adapted from Tree of Light's own checklist
convention.

## Classify the change

| Type | Trigger | Action |
|---|---|---|
| Patch | Bug fix, copy/wording change, no schema change | Version bump, changelog line, build-zip, deploy |
| Minor | New screen/behaviour, no schema change | As above, plus a full relevant `test-notes/TEST_PLAN.md` section |
| **Database migration** | New or changed table | As above, plus explicit deactivate/reactivate note in the release, and confirmation the migration is idempotent (re-running it on an already-migrated database is a no-op) |
| Major | Breaking schema change, capability change | Manual migration plan — do not deploy without the project owner's sign-off |

## Pre-deploy

- [ ] `php -l` run on every changed file, clean
- [ ] Relevant throwaway CLI verification script run for any money-math,
      state-transition, or concurrency change — not just a code read
- [ ] Relevant section(s) of `test-notes/TEST_PLAN.md` walked through on LocalWP
- [ ] Coexistence check: Tree of Light (and any other active sibling plugin)
      still functions normally with this plugin's new version active
- [ ] Version bumped in both the plugin header and the `GRANTS_VERSION`
      constant
- [ ] Changelog line added to `README.md`
- [ ] `scripts/build-zip` run; `dist/<version>.zip` contents verified
- [ ] SiteGround backup created and confirmed

## Deploy

- [ ] Upload/install the new version
- [ ] If a migration shipped: deactivate, reactivate, confirm it ran
- [ ] Confirm the admin menu and Settings screen load without errors
- [ ] Confirm Settings → General → Timezone is a **city** (London), not a
      fixed "UTC+0" offset — round opening/closing times depend on it, and a
      fixed offset ignores British Summer Time (the round screen warns if so)
- [ ] Confirm Tree of Light (and other active sibling plugins) still work

## Post-deploy

- [ ] Note the deployed version, commit hash, and date here or in the PR
- [ ] Watch for notification-queue failures in the first hours after deploy if
      this release touched mail/notifications
- [ ] If anything fails: see "Rollback" in `DEPLOY_TO_PRODUCTION.md` — don't
      improvise under pressure
