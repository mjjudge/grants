<?php
/**
 * Email template (plain text) — new-application notice to staff.
 *
 * Enough to know something needs attention — reference, round/fund,
 * organisation, amount, admin link — and deliberately not a copy of the
 * submitted answers (docs/03, Communication).
 *
 * Available variables (all strings, plain text — not HTML-escaped):
 *   $reference, $fund_name, $round_label, $organisation, $amount, $received,
 *   $admin_url, $help_email, $from_name, $contact_name (unused here)
 */
defined( 'ABSPATH' ) || exit;

echo __( 'A new grant application has been submitted.', 'rotary-grants' ) . "\n\n";
echo __( 'Reference:', 'rotary-grants' ) . ' ' . $reference . "\n";
echo __( 'Fund:', 'rotary-grants' ) . ' ' . $fund_name . "\n";
echo __( 'Funding round:', 'rotary-grants' ) . ' ' . $round_label . "\n";
echo __( 'Organisation:', 'rotary-grants' ) . ' ' . $organisation . "\n";
echo __( 'Amount requested:', 'rotary-grants' ) . ' ' . $amount . "\n";
echo __( 'Received:', 'rotary-grants' ) . ' ' . $received . "\n\n";
echo __( 'View it (login required):', 'rotary-grants' ) . "\n" . $admin_url . "\n\n";
echo __( 'You are receiving this because your address is in the Rotary Grants notification list (Rotary Grants → Settings).', 'rotary-grants' ) . "\n";
