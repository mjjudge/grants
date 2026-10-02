<?php
/**
 * Email template (plain text) — acknowledgement to the applicant.
 *
 * Deliberately limited to what docs/03 lists: reference, round, received
 * date, amount requested and help route. No internal notes, no eligibility
 * verdict, no other answers.
 *
 * Available variables (all strings, plain text — not HTML-escaped):
 *   $reference, $fund_name, $round_label, $organisation, $amount, $received,
 *   $contact_name, $help_email, $from_name, $admin_url (unused here)
 */
defined( 'ABSPATH' ) || exit;

/* translators: %s: contact name */
printf( __( 'Dear %s,', 'rotary-grants' ), $contact_name );
echo "\n\n";
/* translators: %s: fund name */
printf( __( 'Thank you for applying for %s funding. We have received your application.', 'rotary-grants' ), $fund_name );
echo "\n\n";
echo __( 'Reference:', 'rotary-grants' ) . ' ' . $reference . "\n";
echo __( 'Funding round:', 'rotary-grants' ) . ' ' . $round_label . "\n";
echo __( 'Organisation:', 'rotary-grants' ) . ' ' . $organisation . "\n";
echo __( 'Amount requested:', 'rotary-grants' ) . ' ' . $amount . "\n";
echo __( 'Received:', 'rotary-grants' ) . ' ' . $received . "\n\n";
echo __( 'Please quote your reference if you contact us. Receiving your application does not mean it has been assessed as eligible, and submitting an application does not guarantee funding. The committee will be in touch once applications have been considered.', 'rotary-grants' ) . "\n\n";
if ( $help_email !== '' ) {
    /* translators: %s: help email */
    printf( __( 'If you have any questions, reply to this email or write to %s.', 'rotary-grants' ), $help_email );
    echo "\n\n";
}
echo $from_name . "\n";
