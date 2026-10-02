# Cache Exclusions — Rotary Grants

**Filled in at G03 (0.4.0).** Written originally as a placeholder, and as a
direct lesson from a problem found in Tree of Light's own
equivalent file: its `deployment-notes/CACHE_EXCLUSIONS.md` lists page slugs
(`/tol-campaign`, `/tol-success`, `/tol-failed`, `/tol-names`) that no longer
match its actual current pages (confirmed in `docs/grants-discovery.md`) — it
was written once and never updated when the real slugs changed. **Do not let
that happen here: update this file in the same commit that changes a public
page's slug or query-string behaviour, not as a follow-up.**

## What the plugin does itself

Any page whose content contains `[rotary_grant_application …]`:

- is sent WordPress `nocache_headers()` (`Cache-Control: no-cache,
  must-revalidate, max-age=0, no-store, private`) and defines
  `DONOTCACHEPAGE` — confirmed in the response headers on LocalWP;
- sets a session cookie **`grants_form_session`** (HttpOnly, SameSite=Lax,
  Secure on HTTPS). The form token is bound to this cookie, so a cached copy
  of the page carries a token that will not verify for anyone else — the
  visitor then sees "this form needed refreshing" with their answers kept,
  rather than a silent failure. That is the safety net, not the plan:
  configure the exclusions below anyway.

Source of truth: `plugin/src/Public/ApplicationFormHandler.php`
(`prepare_page()`, `COOKIE`, `SUBMITTED_ARG`).

## Exclusions to configure on SiteGround

The application pages are ordinary WordPress pages chosen by whoever builds
the site — **one page per fund**, e.g.:

| Page (example slug — record the real ones here) | Shortcode |
|---|---|
| `/apply-tree-of-light/` | `[rotary_grant_application fund="Tree of Light"]` |
| `/apply-club-charity-fund/` | `[rotary_grant_application fund="Club Charity Fund"]` |

Configure in **SiteGround Site Tools → Speed → Caching → Dynamic Cache →
Exclude URLs** (and in any caching plugin/CDN in front of the site):

- [ ] each application page's path (the real slugs, once created), and
- [ ] any URL with the query parameter **`grants_submitted`** (the receipt
      view — personal to the visitor, session-bound), and
- [ ] any request carrying the cookie **`grants_form_session`** if the cache
      supports cookie-based bypass.

POST requests are never cached by SiteGround's dynamic cache, so the form
submission itself needs no rule.

## Checklist

- [ ] Real page slugs entered in the table above
- [ ] Rules configured in SiteGround (not just documented here) — check by
      loading an application page twice in a private window and confirming
      the `grants_form_session` cookie value and the hidden
      `grants_form_token` differ between two *different* private windows
- [ ] Re-check this file whenever a slug, cookie name or query parameter
      changes — in the same commit as the change
