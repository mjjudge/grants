# Cache Exclusions — Rotary Grants

**Not yet applicable — no public page exists until G03.** This file is a
placeholder with the principle to apply, written now so it isn't forgotten
later, and as a direct lesson from a problem found in Tree of Light's own
equivalent file: its `deployment-notes/CACHE_EXCLUSIONS.md` lists page slugs
(`/tol-campaign`, `/tol-success`, `/tol-failed`, `/tol-names`) that no longer
match its actual current pages (confirmed in `docs/grants-discovery.md`) — it
was written once and never updated when the real slugs changed. **Do not let
that happen here: update this file in the same commit that changes a public
page's slug or query-string behaviour, not as a follow-up.**

## Principle (fill in real slugs once G03 ships)

SiteGround's caching (SuperCacher/Dynamic Cache) and any full-page or CDN cache
in front of `rotaryinthevale.org` must not cache pages that render a nonce,
session-bound submission token, or any per-visitor state — caching them causes
stale tokens (submissions fail with a security-check error) or one visitor's
receipt being served to another.

Exclude by **query-string presence**, not only by fixed slug — this is the
approach that actually holds up over a slug rename, unlike Tree of Light's
stale, slug-only version. Once the public application form page exists, its
exact slug and query-string parameters (success/cancel state, any token) go
here, following the same rule-of-thumb Tree of Light intended: exclude by the
presence of whatever parameter names `src/Public/*FormHandler.php` actually
reads (confirm by reading the code when this is filled in, not by guessing from
this placeholder).

## Checklist for whoever fills this in at G03/G04

- [ ] List the exact page slug(s) the public form and any receipt/cancel state
      actually use (query-string-driven on one page, matching Tree of Light's
      own donate-page pattern, is the recommended approach — see
      `docs/ARCHITECTURE.md`)
- [ ] List every query-string parameter name that must trigger a cache bypass
- [ ] Confirm the rule is actually configured in SiteGround Site Tools →
      Speed → Caching (or the caching plugin in use), not just documented here
- [ ] Re-check this file any time a slug or query parameter changes
