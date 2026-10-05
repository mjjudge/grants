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
- [ ] **Settings → Email sending:** From address `funds@rotaryinthevale.org`
      and From name `Rotary in the Vale` entered (the plugin's own defaults are
      generic since 0.10.0 — blank means WordPress's sender and the site name)
- [ ] **Committee page:** a page containing `[rotary_grants_committee]`
      exists (e.g. `/committee/`), is chosen in Settings → Committee page, is
      in the cache exclusions, and a reviewer test account lands on it after
      signing in. Consider a two-factor sign-in plugin for committee accounts
- [ ] **Settings → Retention:** periods agreed with the trustees and
      "Our trustees have agreed these retention periods" ticked; privacy
      notice (Settings link) states the same periods; `grants_privacy`
      given to the agreed person (Access screen)
- [ ] **Settings → Reports:** reporting year start month as agreed with the
      treasurer (default January)
- [ ] **Email (first release with G04, then whenever mail changes):**
      `funds@rotaryinthevale.org` exists as a mailbox or alias and is covered
      by SPF/DKIM in SiteGround Site Tools → Email → Authentication; send one
      test application to a controlled inbox and check it is not marked spam
- [ ] **Scheduled sending:** SiteGround Site Tools → Devs → Cron Jobs has
      `wget -q -O - https://rotaryinthevale.org/wp-cron.php?doing_wp_cron >/dev/null 2>&1`
      every 5 minutes, and `define( 'DISABLE_WP_CRON', true );` is in
      wp-config.php — **or** a decision recorded that site traffic is enough.
      (Check whether the sibling plugins' owners want the same; it affects the
      whole site's WP-Cron, not just this plugin)
- [ ] Rotary Grants → Notifications shows no unexpected Failed rows; Settings
      → "Pause sending" is **unticked** before opening a round
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
