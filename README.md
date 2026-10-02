# Rotary Grants — WordPress Plugin

**Owner:** Rotary in the Vale  
**Repository:** not yet published  
**Version:** 0.1.0 (repository setup only — see "Current status" below)  
**Requires:** WordPress 6.0+, PHP 8.2+ (provisional — see `docs/ARCHITECTURE.md`)  
**Hosted on:** SiteGround shared hosting (same account as the sibling plugins below)

---

## What this is

A WordPress plugin that keeps the persistent record for Rotary in the Vale's
grant-giving: organisations, funding rounds, submitted applications, committee
decisions, approved awards, and the actual payment ledger. **It is not scoped
to Tree of Light** — the club raises money through more than one fund, and a
funding round is a generic concept (each carries its own `fund_name`) so the
same plugin serves whichever fund is currently running a round.

It never moves money. The committee decides awards; the treasurer makes
payments through the existing independent banking process and records them
here afterwards.

This plugin is a sibling to, and entirely independent of:
- `~/myapps/TOL` — Tree of Light fundraising plugin
- `~/myapps/duck-race` — Duck Race fundraising plugin
- `~/myapps/battle-shield-sponsorship` — Battle of Evesham Shield Sponsorship plugin

It shares no code, database table, or credential with any of them — see
`docs/grants-discovery.md` for the evidence this was decided from.

## Current status

**Repository setup only.** Nothing beyond a bootable, inert plugin shell has
been built yet — see `backlog/DECISIONS.md` (DEC-001) and
`docs/grants-discovery.md`'s "G01 — next concrete step" section. The actual
implementation backlog starts at G01 in `backlog/BACKLOG.md`.

---

## Technology stack

| Layer | Choice | Notes |
|---|---|---|
| Platform | WordPress | Hosted on SiteGround, same account as the sibling plugins |
| Plugin | Custom (this repo) | No dependency on paid plugins, no Composer |
| Database | MySQL via `$wpdb` | SiteGround is master system of record; no payment gateway anywhere |
| Payments | None — recorded only | The treasurer pays outside WordPress; this plugin logs it afterwards |
| Email | WordPress `wp_mail()` | Routed through SiteGround SMTP, same as the sibling plugins |

## Repository structure

```
plugin/                    WordPress plugin (the application)
  src/                      (empty until G01 — Core/, Admin/, Public/, Services/,
                             Database/, Mail/, Audit/ to follow Tree of Light's
                             own module shape, see CLAUDE.md)
  assets/
    css/
    js/
  templates/
    admin/
    email/
    public/
  rotary-grants.php         Plugin entry point, version constant, autoloader
                             (currently a deliberately inert stub — see
                             backlog/DECISIONS.md DEC-001)

docs/                       Product brief, architecture, security/privacy,
                             workflows, decisions-needed, and the repository
                             discovery this plugin's design was based on
scripts/build-zip           Packages a release — see "Building a release" below
dist/                       Versioned release ZIPs (rotary-grants-<version>.zip)
test-notes/                 Manual test plan (no automated framework — see CLAUDE.md)
deployment-notes/           Release checklists and rollback procedures
backlog/                    BACKLOG.md and DECISIONS.md — kept locally, not
                             published (see .gitignore), same as Tree of Light
CLAUDE.md                   Agent/coding-discipline instructions — kept locally,
                             not published (see .gitignore)
```

### Building a release

```
scripts/build-zip
```

Reads the version from `plugin/rotary-grants.php`, packages the `plugin/`
folder as `rotary-grants/` (matching the
`wp-content/plugins/rotary-grants/` install path), and writes
`dist/rotary-grants-<version>.zip`. Bump the `Version:` header and the
`GRANTS_VERSION` constant in `plugin/rotary-grants.php` first, add a line to
the Version history table below, then run the script. Matches the convention
used by Tree of Light, Duck Race, and Battle of Evesham Shield Sponsorship —
confirmed directly from those repositories' own `scripts/build-zip`, not
assumed.

**If the release includes a new database migration, a deactivate/reactivate
cycle is required on the live site to run it** — see `CLAUDE.md` and
`deployment-notes/RELEASE_CHECKLIST.md`.

---

## Where to start

1. Read `CLAUDE.md` for the coding/testing discipline this repository expects —
   it combines the product brief in `docs/` with conventions actually proven
   across the sibling plugins' release history.
2. Read `docs/00-proposal.md` through `docs/08-source-notes.md` for the product
   brief, and `docs/grants-discovery.md` for the evidence it's grounded in.
3. Read `backlog/BACKLOG.md` for the ordered task list (G00 is done; G01 is
   next) and `backlog/DECISIONS.md` for what's already been decided.
4. Confirm the still-open rows in `docs/07-decisions-and-launch.md` with the
   project owner before opening a live funding round — none of them are
   guessed, and none should be.

---

## Version history

| Version | Summary |
|---|---|
| 0.1.0 | Repository setup: git init, directory skeleton, `scripts/build-zip` (proven working), `docs/` (product brief adapted from the original build pack, naming corrected to be fund-agnostic, Settings/staff-notification requirement added), `backlog/BACKLOG.md` and `backlog/DECISIONS.md`, `CLAUDE.md`, `test-notes/TEST_PLAN.md`, `deployment-notes/`. No functional plugin code yet — see "Current status" above |
