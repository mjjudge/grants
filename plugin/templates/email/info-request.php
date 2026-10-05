<?php
/**
 * Email template (plain text) — committee request for more information.
 *
 * The request text is written by staff in admin and sent only by an
 * explicit "Send" action (docs/03). Replies go to the help email (Reply-To).
 *
 * Available variables (all strings, plain text — not HTML-escaped):
 *   $reference, $fund_name, $round_label, $organisation, $contact_name,
 *   $request (staff-written text), $help_email, $from_name,
 *   $amount, $received, $admin_url (unused here)
 */
defined( 'ABSPATH' ) || exit;

/* translators: %s: contact name */
printf( __( 'Dear %s,', 'rotary-grants' ), $contact_name );
echo "\n\n";
/* translators: 1: fund name, 2: reference */
printf( __( 'Thank you for your application for %1$s funding (reference %2$s). The committee would like some more information before considering it:', 'rotary-grants' ), $fund_name, $reference );
echo "\n\n";
echo $request . "\n\n";
echo __( 'Please reply to this email, quoting your reference.', 'rotary-grants' ) . "\n\n";
if ( $help_email !== '' ) {
    /* translators: %s: help email */
    printf( __( 'Questions? Write to %s.', 'rotary-grants' ), $help_email );
    echo "\n\n";
}
echo $from_name . "\n";
