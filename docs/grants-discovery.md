# Grants repository discovery (G00)

Copied into this repository from `~/myapps/TOL/docs/grants-discovery.md`, where
it was produced by direct inspection of the Tree of Light codebase on
2026-10-02, per `docs/grants-build-pack/prompts/00-repository-review.md` in that
repository. Naming below has been updated to match the final decisions in
`backlog/DECISIONS.md` (plugin "Rotary Grants", slug `rotary-grants`, namespace
`Rotary\Grants\`, table/capability prefix `grants_` — not the original build
pack's Tree-of-Light-specific proposal) and the repository location question
this document originally left open is now resolved: this repository,
`~/myapps/grants`, is that answer. Everything else is unchanged evidence.

## Repository state (as inspected)

- **Commit:** `e2cb424f8d51600b97ed381b9a09d95181aedf27` ("Update version number
  to 1.0.18 in README"), branch `main` of `~/myapps/TOL`, in sync with
  `origin/main` (`https://github.com/mjjudge/TOL.git`) — 0 commits
  ahead/behind.
- **Reported vs actual plugin version:** the owner's report of v1.0.18 is
  correct — confirmed directly in `plugin/tree-of-light.php:6` (header) and
  `:17` (`TOL_VERSION` constant) of that repository, and matches the latest row
  of the changelog table in its `README.md`.

## Plugin inventory

- **Tree of Light** builds exactly one plugin, installing as
  `wp-content/plugins/tree-of-light/`.
- **Sibling plugins already running alongside it**, each a separate top-level
  repo under `~/myapps/`: `duck-race` (namespace `DuckRace\`, text domain
  `duck-race`) and `battle-shield-sponsorship`. Tree of Light's own
  `scripts/build-zip` says it follows "the same `dist/<slug>-<version>.zip`
  convention used by the Duck Race and Battle of Evesham Shield Sponsorship
  plugins." This is direct, working precedent for multiple independent plugins
  coexisting on the same WordPress site with no shared code — this repository
  (`~/myapps/grants`) is now a fourth.
- **A real cross-talk incident between two of these plugins already happened
  and was fixed in the same working period as this review**: Duck Race and Tree
  of Light share one Stripe account. Stripe delivers every webhook event of a
  subscribed type to *every* registered endpoint on the account, not just the
  one that created the object — Duck Race's endpoint was returning a 4xx for
  every Tree-of-Light checkout event it received (because the event carried no
  `purchase_id`), Stripe logged each as a delivery failure, and the account's
  webhook was at risk of being disabled. Fixed in
  `~/myapps/duck-race/plugin/src/Services/StripeWebhookProcessor.php` by
  acknowledging unrecognised events instead of rejecting them.
  **Relevance to Rotary Grants:** the first release's "no bank transfers, no
  Stripe" design avoids this exact failure class entirely. If a payment gateway
  is ever added later, it must not share a Stripe account (or any other webhook
  endpoint) with Tree of Light or Duck Race without the same "acknowledge,
  don't reject, unrecognised events" discipline.

## Architecture evidence

### Namespace, prefix, autoloading

- Tree of Light's PHP namespace root is `TreeOfLight\`
  (`plugin/tree-of-light.php:23`), mapped to `plugin/src/` by a hand-rolled
  `spl_autoload_register` — **no Composer, no `vendor/`, no `composer.json`
  anywhere in that repo**. Confirmed by `find` for `composer.json`/
  `package.json` at any depth: none exist. Rotary Grants follows the identical
  pattern with its own namespace, `Rotary\Grants\` — see
  `plugin/rotary-grants.php` in this repository.
- Tree of Light's database table prefix is `$wpdb->prefix . 'tol_'` throughout.
  Current tables there: `tol_audit_log`, `tol_campaigns`, `tol_companies`,
  `tol_contacts`, `tol_correction_requests`, `tol_donations`,
  `tol_email_events`, `tol_remembrance_names`. Rotary Grants uses `grants_`, not
  `tolg_` — see "Not scoped to one fund" in `docs/00-proposal.md` for why.
- Tree of Light's WordPress capability prefix is `tol_` (nine capabilities).
  Rotary Grants uses `grants_`.
- Public shortcodes already registered by Tree of Light: `tol_optin_form`,
  `tol_signup_form`, `tol_donation_form`, `tol_remembrance_names`,
  `tol_sponsors`, `tol_sponsor_form`. No collision with this plugin's own
  shortcode (naming pending — see `docs/02-architecture-and-data.md`).
- Public pages Tree of Light creates on activation: `toloptin`, `tol-signup`,
  `tol-remembrance`, `tol-donate`, `tol-sponsors`, `tol-sponsor-us`. None of
  these match the slugs listed in that repository's own
  `deployment-notes/CACHE_EXCLUSIONS.md` (`/tol-campaign`, `/tol-success`,
  `/tol-failed`, `/tol-names`) — **that doc is itself stale against the current
  slugs**, flagged there as a pre-existing issue out of scope for grants work to
  fix.
- Tree of Light's public query-string routing convention,
  `$_GET['tol_action']`, is checked in three handlers, each guarding on its own
  distinct value — no literal collision risk, but it is a shared parameter
  *name*. If Rotary Grants ever wants the same "standalone page via home-URL
  query string" pattern, it should use its own parameter name (e.g.
  `grants_action`) to stay visibly independent.

### Capabilities and roles

Tree of Light's `plugin/src/Core/Roles.php` grants all nine of its capabilities
directly to the built-in WordPress `Editor` role, not just `Administrator` —
confirmed, and already tracked as a known, deliberately-deferred gap in that
repository's own backlog (TOL-145). **Rotary Grants must not repeat this.**
Grant `grants_*` capabilities only to Administrator plus explicitly named
accounts, never to Editor by default — see `CLAUDE.md` in this repository.

### Database migrations

Tree of Light's `Migrator` is a simple version-keyed array of migration classes,
each implementing `up()` with `SHOW COLUMNS`/`SHOW INDEX` existence checks
before altering. Tracked in a `tol_db_version` option. **Confirmed limitation,
stated in that repository's own README:** its migrator only ever runs from the
activation hook, not on an ordinary file update — a deactivate/reactivate cycle
is required on every release that adds a migration. Rotary Grants should mirror
this exact pattern (it is the one piece of this ecosystem proven to work across
18 Tree of Light releases) and must say the same deactivate/reactivate
requirement explicitly in its own release notes — see
`deployment-notes/RELEASE_CHECKLIST.md`.

### Scheduled work

**None exists in any sibling plugin.** No `wp_schedule_event`/cron hook anywhere
in Tree of Light's source. There is no existing pattern to copy for Rotary
Grants' own notification retry queue (`grants_notifications`). This needs a
from-scratch design, and whether SiteGround reliably runs WP-Cron at all for a
low-traffic period is genuinely unverified — see Uncertainties.

### Mail path

Tree of Light's `MailService::send()` calls `wp_mail()` synchronously, inline,
with an event logged for non-test sends. No queue, no retry, no background
delivery anywhere. Rotary Grants' requirement to queue the applicant
acknowledgement and staff notification after the transaction commits, with a
bounded retry policy, is new work — not reusable from Tree of Light, and (per
the scheduled-work finding) has no scheduler to run it on without a fresh
decision.

### Tests and release conventions

- **No automated test framework exists anywhere in this ecosystem.** No
  PHPUnit, no `composer.json`/`package.json`. Tree of Light's only testing
  artefact is a manual, checkbox-driven `test-notes/TEST_PLAN.md` walked
  through by hand in LocalWP before each production deploy.
- **"Test commands" available today are, concretely:** `php -l <file>` per
  changed file, and a manual walkthrough of a test plan. There is no
  `phpunit`/`npm test`/CI to invoke. This directly conflicts with needing to
  verify concurrency and financial-invariant behaviour by anything other than
  careful service-layer review plus one-off CLI verification scripts — see
  `CLAUDE.md`'s Testing discipline section in this repository for the resolved
  approach.
- **Release convention:** no git tags anywhere in this ecosystem. Versioning is
  tracked purely by the plugin header's `Version:` line, a changelog row in the
  README's "Version history" table, and a built, **git-committed** zip at
  `dist/<slug>-<version>.zip` via `scripts/build-zip`. Rotary Grants mirrors
  this exactly — see this repository's own `scripts/build-zip` and
  `README.md`.

### Hosting assumptions

- SiteGround shared/managed hosting, **single-site plan — no staging
  environment exists** for Tree of Light, and by extension none is assumed to
  exist for this plugin either unless the owner says otherwise. LocalWP is the
  only pre-production test environment; deployment is directly to production,
  backed by a SiteGround snapshot backup taken first.
- PHP 8.2.x is the declared target, but even Tree of Light's own
  `docs/ARCHITECTURE.md` treats this as provisional, saying to confirm the PHP
  version with the SiteGround dashboard before relying on it. Rotary Grants
  carries the identical caveat — see `docs/ARCHITECTURE.md` in this repository.
- MySQL/InnoDB, `utf8mb4_unicode_520_ci` is the declared target. Whether
  SiteGround's actual MySQL reliably supports the transactional behaviour the
  budget/payment invariants need is not independently verified anywhere in this
  ecosystem — unresolved, see Uncertainties.
- SiteGround runs its own page cache. Tree of Light's own cache-exclusion
  documentation is stale against its current page slugs (see above) and cannot
  be copied as a template as-is; the *principle* — exclude by query-string
  presence, not only by fixed slug — is sound and is reused in this
  repository's own `deployment-notes/CACHE_EXCLUSIONS.md`.

### Extension hooks or modules suitable for grants

**None exist in Tree of Light.** Zero custom `do_action`/`apply_filters` calls
anywhere in its source, no shared service-locator, no public PHP API intended
for third-party use. The only way to "hook into" it would be editing its own
source files directly — exactly the donor-flow regression risk a separate
plugin avoids.

## Recommendation: separate companion plugin — confirmed by this evidence

| Criterion | Evidence |
|---|---|
| Does Tree of Light expose hooks a module could attach to? | No — zero custom hooks anywhere. |
| Is there a shared service layer safe to call from outside? | No — every class is instantiated directly by name; nothing is a published API. |
| Does a module need to keep working if the other plugin is deactivated? | Never attempted anywhere in that codebase — no precedent for conditional coexistence. |
| Is there precedent for independent sibling plugins on this install? | Yes — Duck Race and Battle Shield Sponsorship already do exactly this. |
| Would extending Tree of Light's own `plugin/` directory put donor-flow code at risk? | Yes, structurally — it would add grants' tables, capabilities, form, and admin section into the exact autoloader/migrator/menu boot sequence donations and sponsorships already share. |

This repository is the result: its own directory under `~/myapps/`, its own
namespace, its own migrator, its own capability prefix, its own `dist/`.

## Resolved since this document was first written

- **Repository location** — resolved: `~/myapps/grants`.
- **Naming** — resolved: "Rotary Grants" / `rotary-grants` / `Rotary\Grants\` /
  `grants_`, not Tree-of-Light-specific, per the owner's correction that more
  than one fund needs this system. See `docs/00-proposal.md` and
  `backlog/DECISIONS.md`.
- **Automated test framework decision** — resolved for now: none, matching the
  sibling plugins, with the explicit manual/scripted-verification discipline in
  `CLAUDE.md` substituting for it. Revisit only if a specific task's risk
  justifies the cost.

## Uncertainties still open (not resolved by source inspection alone)

- Live SiteGround PHP/MySQL versions are declared in docs but not
  independently re-confirmed from a shell/dashboard — treat as provisional.
- Whether SiteGround's scheduler reliably runs WP-Cron for a low-traffic
  off-season grants round — no evidence either way; no sibling plugin uses cron
  to check against.
- Whether transactional (InnoDB-backed, multi-statement) behaviour is reliably
  available on the actual host for the budget-reservation and payment-ledger
  invariants — needs its own direct test, not an assumption from Tree of
  Light's code (which never uses an explicit transaction anywhere).
- The real operational contact/help email, funding-round dates, budget, award
  cap, and notification recipient addresses are explicitly out of scope for
  discovery and are not invented here — see `docs/07-decisions-and-launch.md`.
- Whether a fuller multi-fund model (beyond the `fund_name` label on a round) is
  ever actually needed — deferred until a specific fund's rules demonstrate the
  label isn't enough.

## G01 — next concrete step (done in 0.2.0 — see README version history and backlog/DECISIONS.md DEC-007/DEC-008)

This document, and the rest of this repository's setup (git init, directory
skeleton, `scripts/build-zip`, all of `docs/`, `backlog/`, `CLAUDE.md`,
`test-notes/`, `deployment-notes/`), is the **repository setup** step. It is not
G01. The entry file `plugin/rotary-grants.php` in this repository is a
deliberately inert stub — header, constants, and autoloader registration only,
no activation hook, no boot sequence — exactly so that `scripts/build-zip` could
be proven working end to end (confirmed: `dist/rotary-grants-0.1.0.zip` builds
successfully) without front-running the actual backlog work.

**G01 ("Bootstrap companion plugin and capabilities," per `backlog/BACKLOG.md`)
is the next task**, when work on this repository begins:

1. `src/Core/Installer.php` + `register_activation_hook`/
   `register_deactivation_hook` in `plugin/rotary-grants.php` (currently absent
   on purpose).
2. `src/Core/Roles.php`: the capability table from
   `docs/03-workflows-and-permissions.md`, prefixed `grants_`, granted only to
   `administrator` by default — not `editor`.
3. `src/Core/Plugin.php` + `src/Admin/Menu.php`: a `plugins_loaded` boot
   registering one top-level admin menu ("Rotary Grants"), gated on
   `grants_access`, with a placeholder "not yet configured" screen only.
4. The Settings screen stub (`src/Admin/SettingsPage.php`) that G01's own
   completion evidence should include, since the notification-recipient
   requirement added to this brief depends on it existing early, not bolted on
   in G04.

**Validation for G01:**

- Fresh WordPress/LocalWP install: activate `rotary-grants` with Tree of Light
  **inactive** — admin menu appears, no PHP notices, no reference to any `tol_*`
  table or class.
- Activate Tree of Light alongside it — both menus appear independently; run
  the relevant `[SMOKE]` section of Tree of Light's own `test-notes/TEST_PLAN.md`
  and confirm no change in its behaviour.
- Deactivate Tree of Light with Rotary Grants still active — Rotary Grants'
  menu and placeholder screen remain fully functional.
- Log in as a user with only `subscriber`/`editor` WordPress roles (no
  `grants_*` capability granted) — confirm denial by direct URL/request, not
  just hidden menu visibility.
- `php -l` on every new file; record the result in the G01 commit message.
