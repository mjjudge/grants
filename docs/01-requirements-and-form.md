# Requirements and online form

Adapted from the original build pack. Field policy content is unchanged from the
source Tree of Light 2026 application form — the only edits here are naming
(this plugin is not Tree-of-Light-specific) and the addition of the Settings/
notification requirement, which was missing from the original brief.

## Preserve the existing policy

The attached 2026 Tree of Light application form supports organisations in
Evesham and nearby surrounding villages, particularly locally connected causes
where a donation makes a significant difference. It seeks one-off initiatives
rather than recurring running costs. Do not invent a mileage radius,
charity-registration requirement, income threshold, or maximum award. **This
eligibility wording belongs to the Tree of Light fund specifically** — a
different fund's round may need its own eligibility text; the form must render
the active round's own configured wording, not a hardcoded Tree of Light
paragraph.

Applicants are expected to have a bank account in the organisation's name, a
governing document outlining its objectives, local leadership and operation, and
a committee or support group.

The original exclusions are private businesses; national organisations unless
they are local groups with their own management committee and funds definitely
go to the local community; building work; staff parties, prize money, or gifts;
political work; religious work whose main focus is proselytising; organisations
that give funds to other organisations; and staff costs and overheads. Display
these separately, with the national-group exception intact. An eligibility flag
supports committee review; it is not an automated award decision.

## Field mapping

R = required proposed online field. O = optional. New = useful proposed
addition requiring committee agreement. All lengths are proposed configurable
server-side limits, not policy from the source.

| Original field or requirement | Online field | Rule |
|---|---|---|
| Organisation name | organisation_name | R; plain text; 200 characters |
| Your name | contact_name | R; 150 characters |
| Position | contact_role | R; 150 characters |
| Phone | contact_phone | R proposed; preserve international formats; 50 characters |
| Email | contact_email | R; valid email; 254 characters; acknowledge only this address |
| Charity number if applicable | charity_number | O; text, not integer; no number is acceptable for a community group |
| Website | website_url | O; http/https only |
| Facebook page | facebook_url | O; http/https only |
| Other social media | other_social_urls | O; up to three labelled http/https URLs |
| Short organisation overview | organisation_overview | R; textarea; proposed 2,000 characters |
| Amount, use and difference combined | requested_amount_gbp | R; positive GBP value, maximum two decimal places |
| Same combined question | proposed_use | R; textarea; proposed 4,000 characters |
| Same combined question | expected_difference | R; textarea; proposed 3,000 characters |
| Organisation bank account | has_organisation_bank_account | R yes/no; declaration only; no account number |
| Governing document | has_governing_document | R yes/no; staff verify separately as needed |
| Locally led and run | locally_led_and_run | R yes/no plus explanation for exceptions |
| Committee or support group | has_committee_or_support_group | R yes/no |
| Local community and national-group exception | is_local_branch; branch_explanation | New; explanation required for a national organisation's local group |
| One-off initiative | one_off_initiative | New R confirmation, with explanation if uncertain |
| Exclusions | exclusions_acknowledged | New R; affirm understanding, not blanket legal certification |
| Publicity undertaking | publicity_acknowledged | Preserve current requirement pending committee decision; snapshot wording |
| Possible presentation on collection | presentation_acknowledged | Preserve current form wording; snapshot wording |
| Signature | declaration_name; declaration_confirmed | R; typed name and authority/accuracy affirmation; do not claim equivalence to a specific legal signature standard |
| Date | submitted_at | Server timestamp; display local date; no applicant backdating |
| Placeholder email instruction | help_email | **Admin-configurable on the Settings screen** (see Settings and notifications below); block opening a round until set |
| No source field | organisation_town; organisation_postcode; local_benefit | New R proposed; locality evidence, not an invented geographic cutoff |
| No source field | future_round_email_opt_in | New O unchecked checkbox; independent of eligibility or award |
| No source field | privacy_notice_acknowledged | New R acknowledgment of notice; not consent to all processing |

The online form must show the round's own label and fund name explicitly,
rather than assuming Tree of Light or the current calendar year. Bank account
existence is only a declaration; before payment the treasurer verifies
recipient bank details through an independently confirmed channel outside
WordPress.

## Settings and notifications (added to this brief — not in the original build pack)

The original brief's "Placeholder email instruction" row noted that the source
form's destination email was a placeholder, but did not specify *where* the real
address should be configured, nor address who on staff should be told when a new
application arrives. Both need a Settings screen:

- **Help/contact email** — shown on the public form and in the applicant's
  acknowledgement email. Configurable, not hardcoded. Opening a round is blocked
  until this is set (already specified in the original brief; now has a concrete
  home).
- **Notification recipients** — a configurable list of staff email addresses
  (not the applicant) notified automatically whenever an application is
  successfully submitted, so the coordinator doesn't have to poll the admin area
  to know a new one has arrived. Plain list (one address per line or
  comma-separated), validated as email addresses, editable without a code
  change, capability-gated to settings management. See
  `docs/03-workflows-and-permissions.md`'s Communication section for the
  notification's own content/behaviour, and `docs/02-architecture-and-data.md`
  for where this is stored.

## Proposed page wording

**Apply for [fund name] funding** (fund name comes from the active round's
configuration — e.g. "Apply for Tree of Light funding" when that round is open)

[Fund-specific introductory wording, configured per round] welcomes applications
for one-off initiatives with a strong local connection where a donation would
make a significant difference. Please read the eligibility rules before
applying.

Show the configured round label, fund name, opening date, closing date, and
contact email here. Explain when a decision is expected only if the committee
has set a date. Do not promise an award or a particular response time.

**Organisation and contact** — Tell us about your organisation and who we
should contact about this application.

**Your initiative** — How much are you requesting? What will you use it for?
How will it make a difference? Who in the local community will benefit?

**Applicant declaration** — I am authorised to apply on behalf of this
organisation. To the best of my knowledge, the information provided is
accurate. I understand that submitting an application does not guarantee
funding.

**Publicity** — The source form (Tree of Light specifically) asks successful
applicants to be prepared to feature in Rotary publicity and says
representatives may be asked to give a brief address when collecting the
donation. Keep this visible for rounds where it applies. Let the committee
decide how exceptions, photography permissions, and individual safeguarding
needs are handled; do not treat the undertaking as unrestricted permission to
publish personal details. A different fund's round may configure different
publicity wording.

**Future funding rounds** — Optional: Please email me when future funding
rounds open. I can withdraw this permission at any time. Leaving this unticked
will not affect this application.

**Privacy** — Link to an approved notice explaining the responsible
organisation, uses of data, access, retention, and contact route. Acknowledgment
of this notice is separate from optional future-round emails.

## Submission behaviour

Validate again on the server. Render errors beside their fields and in a
keyboard-focusable summary; preserve entered answers. Accept legitimate
punctuation and Unicode names. Store declared answers even when staff need to
clarify eligibility. Use POST and a durable idempotency key to prevent retries
becoming new applications. Never accept the round, decision, approved amount,
or payment status from untrusted client fields.

Show success only after the application has committed. Return a non-sequential
public reference, a receipt summary, and the help route. An email failure must
not lose or duplicate the saved application; tell the applicant the application
was received without promising delivery. The configured staff notification list
is also notified at this point (see Settings and notifications above) — a
failure to notify staff must not be shown to the applicant as an error, and must
not block or duplicate the saved application either.

No public organisation search, previous-award lookup, or application retrieval
by reference alone. Email address is not an organisation identifier.
