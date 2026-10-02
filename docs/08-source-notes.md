# Source notes and evidence limits

Adapted from the original build pack, with the repository-access gap it flagged
now closed, and naming references corrected throughout this `docs/` directory.

## Supplied evidence

- `APPLICATION FORM TREE OF LIGHT 2026 FUND(1).docx`: current application fields, locality, expectations, exclusions, publicity undertaking, and possible presentation (Tree of Light specifically — the source policy baseline for that fund's rounds, not a universal eligibility rule for every fund this plugin may serve). Its destination email is a placeholder. The form supplies no deadline, budget, award cap, or retention period.
- `ToL Overall Architecture.docx` and `Tree of Life Architecture.odt`: legacy ecosystem and discussion of donor communications. Extractable text indicates standalone Mailchimp and outputs to the Evesham Journal. These documents do not establish the structure of the actual Tree of Light v1.0.18 codebase.
- `Tree of Light Software Instructions V6- 2023.pdf`: legacy donor administration, exports, thank-you cards, and annual communications. Not a technical specification for grants work. Do not carry forward its old browser/punctuation restrictions as engineering requirements.
- `Tree of Life table.odt`: legacy donor fields, including contact/address, Gift Aid, donation, and remembering information. Not a beneficiary grant schema.
- `URL-for-target-site.txt`: https://rotaryinthevale.org/
- `Legacy-Tree-of-Light-online-form-being-migrated.txt`: historic donor form link; no grant requirements inferred from its unseen page.

## Repository review — resolved

The original build pack could not inspect the Tree of Light repository when it
was written (public retrieval unavailable, clone required authentication). That
gap is now closed: `docs/grants-discovery.md` in this repository is the actual,
evidence-based review of `~/myapps/TOL` (commit `e2cb424f`, v1.0.18) performed
from inside that checkout, with file paths and line numbers for every claim. The
separate-companion-plugin recommendation, provisional in the original pack, is
**confirmed** by that review — see `docs/00-proposal.md`'s "Why a separate
repository" section. Naming in this `docs/` directory has also been corrected
away from the original pack's "Tree of Light Grants"/`tolg_` proposal, per the
project owner's own correction that this system serves more than one fund — see
`backlog/DECISIONS.md`.

The existing application form was inspected from the supplied local copy. It
was not modified. No live website, applicant data, or bank record has been
changed by any of this planning work.

## Official engineering and privacy references

Checked 2 October 2026. Recheck when implementing, especially against actual
WordPress/hosting versions — this repository's own `docs/ARCHITECTURE.md`
explicitly treats the hosting facts as provisional, not independently
re-verified from inside this planning session.

- WordPress custom tables and migrations: https://developer.wordpress.org/plugins/creating-tables-with-plugins/
- WordPress nonce limitations, capability checks, and guest caveat: https://developer.wordpress.org/apis/security/nonces/
- WordPress REST cookie authentication: https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
- ICO purpose limitation: https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/purpose-limitation/
- ICO storage limitation: https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-protection-principles/a-guide-to-the-data-protection-principles/storage-limitation/
- ICO electronic mail rules, including charitable purposes soft opt-in: https://ico.org.uk/for-organisations/direct-marketing-and-privacy-and-electronic-communications/guidance-on-direct-marketing-using-electronic-mail/how-do-we-comply-with-the-pecr-electronic-mail-marketing-rules/

A companion plugin, data model, workflow, opt-in design, and launch process are
recommendations from this review. They are not described as requirements
already approved by the committee — see the still-open rows in
`docs/07-decisions-and-launch.md`.
