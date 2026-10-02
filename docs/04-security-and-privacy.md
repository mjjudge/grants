# Security and privacy implementation requirements

Carried over from the original build pack unchanged in substance — this
document was already written generically and needed no Tree-of-Light-specific
corrections. The club or responsible fund entity must approve the privacy
notice, lawful bases, and retention schedule. Do not describe this pack as a
legal compliance certification.

## Public form

No public read API for applications, organisation history, notes, payments, or
exports. Use a POST submission route with strict allowlisted fields and
body/field limits. Validate on the server; sanitise according to field type;
escape for each output context; use prepared database queries. Store text as
text and reject dangerous URL schemes.

WordPress nonces are not authentication or authorisation. Core guest nonces
share user ID zero, so use a session-bound submission token for guest form
actions, appropriate SameSite cookies, and origin checks as defence in depth.
Obtain fresh token data outside full-page caching. Do not rely on nonces as spam
prevention or submission idempotency.

Apply a honeypot and rate limits to submission and acknowledgement sending,
with a shared-network-friendly threshold and accessible recovery message.
Minimise and time-limit rate-limit identifiers, and trust forwarded client IP
only from verified host proxies. Add a third-party challenge only if needed and
approved, with privacy and accessibility impact assessed.

Atomic duplicate prevention needs a unique, server-controlled submission key
plus request fingerprint. Concurrent retries with the same key and payload
return the same receipt; reuse with a different payload is rejected. A
client-side disabled button alone is insufficient.

## Staff access

Logged-in WordPress sessions, action nonces, and custom capability checks are
all required for staff mutations. REST routes need appropriate permission
callbacks. Check object/action rights and conflicts of interest inside service
operations. Use optimistic row versions for concurrent edits and transactional
locking for budget/payment changes.

Do not place personal data in page URLs, analytics events, public error
messages, or general debug logs. Disable shared caching for sensitive screens.
Receipt pages must not be a public lookup keyed by a guessable reference.
Private API responses must not leak counts or candidate matches to applicants.

Escape spreadsheet-leading formula characters in CSV text cells, including
leading whitespace followed by `=`, `+`, `-`, `@`, tab, or carriage return. Keep
amount/date columns typed and predictable. Exports require capability checks, an
action nonce, an audit entry, and download headers; no public Media Library
storage.

## Data purpose and future contact

Application handling, committee review, award administration, and financial
record keeping are distinct from invitations about later funding rounds. The
proposed optional opt-in is a deliberately simple product choice, not a claim
that all charity email requires consent. ICO guidance now includes a charitable
purposes soft opt-in subject to conditions; eligibility for that route has not
been established for this fund.

Do not make optional future-round email permission a condition of funding.
Record permission purpose, wording version, date, source, and withdrawal.
Retain the current preference separately from the submitted application
snapshot. A historic contact list is not evidence of permission. Withdrawn or
inactive contacts must not appear in future-round exports.

Reuse organisation history only for the disclosed purpose. Do not automatically
enrol applicants into donor Mailchimp lists or copy donor contacts into the
grants database — and the reverse: do not copy grants applicant contacts into
any fundraising plugin's donor list either (see
`docs/02-architecture-and-data.md`, "Do not reuse Tree of Light's tables"). New
purposes require a documented assessment and revised notice where appropriate.

The publicity undertaking from the Tree of Light form needs committee review
for each fund that uses it. Distinguish publishing the organisation's award from
identifying individual beneficiaries, using photographs, or making personal
contact details public. Do not ask for named beneficiaries, medical details, or
other unnecessary sensitive information in free-text guidance.

## Retention and rights

Before opening applications, configure approved retention categories for
unsuccessful applications, successful awards/payment records, obsolete contact
details, notification logs, security identifiers, and imports. No fixed period
is asserted here. Keep necessary organisation/award history while removing or
anonymising personal contact and free-text details when their justified period
ends. Preserve accounting records when a lawful obligation requires them.

Support WordPress personal data exporter/eraser hooks for contact emails,
covering snapshots, contacts, preferences, reviews where relevant, notification
recipients and their own log entries, and retained import material. Email
matching alone must not authorise disclosure; use WordPress's verified request
workflow and staff review for shared-address cases. Erasure can be partial with
a documented retained-record reason.

Retention processing must include personal data in audit change summaries and
backups under a documented expiry/recovery policy. Do not promise an immediate
deletion from every backup. Do not delete audit evidence indiscriminately or
retain full old contact values forever in audit logs.

## Mail and operations

Queue acknowledgement — and the staff new-application notification — after the
application transaction commits. Store minimal notification payload, retry
status, and recipient. `wp_mail` success means acceptance by the configured
mail route, not inbox delivery. Retries may still produce duplicate messages
after uncertain outcomes; receipts carry the same reference. Use a bounded
retry policy and a staff-visible failure queue.

WP-Cron depends on site traffic by default. Discover whether hosting provides a
dependable scheduled runner — confirmed by inspection: no sibling plugin
(Tree of Light, Duck Race, Battle Shield Sponsorship) currently uses WP-Cron at
all, so there is no existing precedent to check against on this specific host;
treat this as genuinely unverified rather than assuming it works. Confirm mail
delivery with a controlled test inbox on the one real pre-production
environment available (LocalWP — there is no staging site; see
`docs/ARCHITECTURE.md`). Do not send test mail to real applicants.

Deactivation retains records. Uninstall retains records by default; an
explicit, documented erase procedure needs backup and authorised action. Store
no bank numbers, token secrets, or payment-provider credentials in this module.
Private attachments, applicant links, and live banking integration need
separate designs before later implementation.
