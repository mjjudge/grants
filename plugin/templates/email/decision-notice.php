<?php
/**
 * Email template (plain text) — decision notice to the applicant.
 *
 * The decision text is drafted from DecisionService::notice_template(),
 * reviewed/edited by staff, and sent only by an explicit "Send" action.
 *
 * Available variables (all strings, plain text — not HTML-escaped):
 *   $reference, $fund_name, $round_label, $organisation, $contact_name,
 *   $request (the staff-approved decision text), $help_email, $from_name,
 *   $amount, $received, $admin_url (unused here)
 */
defined( 'ABSPATH' ) || exit;

/* translators: %s: contact name */
printf( __( 'Dear %s,', 'rotary-grants' ), $contact_name );
echo "\n\n";
/* translators: 1: reference, 2: organisation */
printf( __( 'Application %1$s — %2$s', 'rotary-grants' ), $reference, $organisation );
echo "\n\n";
echo $request . "\n\n";
if ( $help_email !== '' ) {
    /* translators: %s: help email */
    printf( __( 'If you have any questions, reply to this email or write to %s.', 'rotary-grants' ), $help_email );
    echo "\n\n";
}
echo $from_name . "\n";
