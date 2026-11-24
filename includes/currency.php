<?php
/**
 * CampusLink marketplace currency configuration.
 *
 * Display label: GHC (Ghana Cedis) — customer-facing.
 * ISO / Paystack API code: GHS — never send "GHC" to Paystack.
 */

if (!function_exists('sec_env')) {
    require_once __DIR__ . '/security_core.php';
}

/** ISO 4217 code used for new marketplace money and Paystack (default GHS). */
function app_currency_iso(): string {
    $c = strtoupper(trim(sec_env('CAMPUSLINK_CURRENCY', sec_env('PAYSTACK_CURRENCY', 'GHS'))));
    return preg_match('/^[A-Z]{3}$/', $c) ? $c : 'GHS';
}

/** Human-readable currency name. */
function app_currency_name(): string {
    return trim(sec_env('CAMPUSLINK_CURRENCY_NAME', 'Ghana Cedi')) ?: 'Ghana Cedi';
}

/**
 * Customer-facing label for an ISO code.
 * GHS (and empty/default) → GHC. Other codes kept as-is for historical honesty.
 */
function currency_display_label(?string $iso = null): string {
    $code = strtoupper(trim((string)($iso ?? '')));
    if ($code === '' || $code === 'GHS') {
        return 'GHC';
    }
    if ($code === 'GHC') {
        return 'GHC';
    }
    return $code;
}

/** Decimal places for marketplace money. */
function app_currency_decimals(): int {
    return 2;
}

/**
 * Format a monetary amount for UI.
 * Default / GHS → "GHC 1,500.00". Historical non-GHS (e.g. USD) keeps its ISO label.
 *
 * @param float|int|string|null $amount
 * @param string|null $isoCurrency Optional ISO code from payments.currency
 */
function format_currency($amount, ?string $isoCurrency = null): string {
    $n = (float)$amount;
    $label = currency_display_label($isoCurrency);
    return $label . ' ' . number_format($n, app_currency_decimals(), '.', ',');
}

/**
 * Convert major units (e.g. GHC 130.00) to minor units for Paystack (pesewas).
 * Paystack always expects the ISO code GHS separately — not this helper.
 */
function currency_to_minor_units($majorAmount): int {
    return (int)round(((float)$majorAmount) * 100);
}

/**
 * ISO code to store on new payment / order money rows.
 * Always the marketplace ISO (GHS), independent of display label GHC.
 */
function app_payment_currency(): string {
    return app_currency_iso();
}

/** Commission percentage snapshotted on each order line, in basis points. */
function marketplace_commission_bps(): int {
    $bps = filter_var(sec_env('CAMPUSLINK_COMMISSION_BPS', '0'), FILTER_VALIDATE_INT);
    return max(0, min(10000, $bps === false ? 0 : $bps));
}
