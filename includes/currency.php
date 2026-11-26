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

/**
 * Environment fallback for the commission rate, in basis points.
 * 500 = 5%. Used only when marketplace_settings has no commission_bps row.
 */
function marketplace_commission_bps_from_environment(): int {
    $bps = filter_var(sec_env('CAMPUSLINK_COMMISSION_BPS', '0'), FILTER_VALIDATE_INT);
    return max(0, min(10000, $bps === false ? 0 : (int)$bps));
}

function marketplace_settings_table_ready(?mysqli $conn = null): bool {
    static $ready = false;
    if ($ready) {
        return true;
    }
    if (!$conn instanceof mysqli) {
        $conn = ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null;
    }
    if (!$conn instanceof mysqli) {
        return false;
    }
    $result = $conn->query(
        "SELECT 1 FROM information_schema.tables
         WHERE table_schema = DATABASE() AND table_name = 'marketplace_settings' LIMIT 1"
    );
    $ready = $result instanceof mysqli_result && $result->num_rows === 1;
    if ($result instanceof mysqli_result) {
        $result->free();
    }
    return $ready;
}

/**
 * Active commission rate in basis points.
 *
 * Precedence:
 * 1. marketplace_settings.commission_bps when that row exists and is an integer
 * 2. CAMPUSLINK_COMMISSION_BPS from the environment
 * 3. 0
 *
 * Order creation snapshots the returned value. This function does not update
 * existing order lines.
 */
function marketplace_commission_bps(): int {
    $conn = ($GLOBALS['conn'] ?? null) instanceof mysqli ? $GLOBALS['conn'] : null;
    if ($conn instanceof mysqli && marketplace_settings_table_ready($conn)) {
        $stmt = $conn->prepare(
            "SELECT setting_value FROM marketplace_settings WHERE setting_key = 'commission_bps' LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $bps = filter_var($row['setting_value'], FILTER_VALIDATE_INT);
            if ($bps !== false) {
                return max(0, min(10000, (int)$bps));
            }
        }
    }
    return marketplace_commission_bps_from_environment();
}

/**
 * Parse an administrator-entered percent. Accepts 0 through 100 with at most
 * two decimal places. Returns basis points (5% = 500).
 */
function marketplace_commission_bps_from_percent(string $raw): int {
    $raw = trim($raw);
    if (!preg_match('/^(?:100(?:\.0{1,2})?|[0-9]{1,2}(?:\.[0-9]{1,2})?)$/', $raw)) {
        throw new InvalidArgumentException('Enter a commission rate from 0 to 100 percent, with at most two decimal places.');
    }
    $parts = explode('.', $raw, 2);
    $whole = (int)$parts[0];
    $fraction = str_pad($parts[1] ?? '', 2, '0');
    $bps = ($whole * 100) + (int)$fraction;
    if ($bps < 0 || $bps > 10000) {
        throw new InvalidArgumentException('Enter a commission rate from 0 to 100 percent.');
    }
    return $bps;
}
