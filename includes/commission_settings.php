<?php
/**
 * Administrator writes for the marketplace commission rate.
 * Reads stay in marketplace_commission_bps(). Historical order lines are never updated.
 */
require_once __DIR__ . '/admin_vendor.php';

function assert_commission_admin(mysqli $conn, int $adminId): void {
    if ($adminId < 1) {
        throw new RuntimeException('Only an administrator can change the commission rate.');
    }
    $stmt = $conn->prepare("SELECT role, is_active FROM users WHERE id=? LIMIT 1");
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account || (string)$account['role'] !== 'admin' || (int)$account['is_active'] !== 1) {
        throw new RuntimeException('Only an active administrator can change the commission rate.');
    }
}

/**
 * @return array{
 *   bps:int,
 *   source:string,
 *   updated_at:?string,
 *   updated_by:?int,
 *   admin_name:?string,
 *   storage_ready:bool
 * }
 */
function marketplace_commission_status(mysqli $conn): array {
    $fallback = marketplace_commission_bps();
    $status = [
        'bps' => $fallback,
        'source' => 'environment',
        'updated_at' => null,
        'updated_by' => null,
        'admin_name' => null,
        'storage_ready' => marketplace_settings_table_ready($conn),
    ];
    if (!$status['storage_ready']) {
        return $status;
    }
    $stmt = $conn->prepare(
        "SELECT s.setting_value, s.updated_at, s.updated_by, u.name AS admin_name
         FROM marketplace_settings s
         LEFT JOIN users u ON u.id = s.updated_by
         WHERE s.setting_key = 'commission_bps'
         LIMIT 1"
    );
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return $status;
    }
    $bps = filter_var($row['setting_value'], FILTER_VALIDATE_INT);
    if ($bps === false) {
        return $status;
    }
    $status['bps'] = max(0, min(10000, (int)$bps));
    $status['source'] = 'database';
    $status['updated_at'] = (string)$row['updated_at'];
    $status['updated_by'] = $row['updated_by'] !== null ? (int)$row['updated_by'] : null;
    $status['admin_name'] = $row['admin_name'] !== null ? (string)$row['admin_name'] : null;
    return $status;
}

/** @return list<array<string,mixed>> */
function marketplace_commission_history(mysqli $conn, int $limit = 40): array {
    $limit = max(1, min(100, $limit));
    $stmt = $conn->prepare(
        "SELECT a.id, a.created_at, a.before_json, a.after_json, u.name AS admin_name
         FROM admin_audit_log a
         INNER JOIN users u ON u.id = a.admin_id
         WHERE a.action = 'commission_rate_update'
         ORDER BY a.id DESC
         LIMIT ?"
    );
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/**
 * Persist a percent string (0–100, at most two decimal places).
 * @return array{saved:bool,bps:int}
 */
function save_marketplace_commission_percent(mysqli $conn, string $rawPercent, int $adminId): array {
    assert_commission_admin($conn, $adminId);
    if (!marketplace_settings_table_ready($conn)) {
        throw new RuntimeException('Commission storage is not installed. Apply database migration 013 before saving.');
    }
    $bps = marketplace_commission_bps_from_percent($rawPercent);
    $current = marketplace_commission_status($conn);
    if ($current['source'] === 'database' && (int)$current['bps'] === $bps) {
        return ['saved' => false, 'bps' => $bps];
    }
    $before = [
        'commission_bps' => (int)$current['bps'],
        'source' => (string)$current['source'],
    ];
    $after = [
        'commission_bps' => $bps,
        'source' => 'database',
    ];
    $value = (string)$bps;
    $stmt = $conn->prepare(
        "INSERT INTO marketplace_settings (setting_key, setting_value, updated_by)
         VALUES ('commission_bps', ?, ?)
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = CURRENT_TIMESTAMP"
    );
    $stmt->bind_param('si', $value, $adminId);
    $stmt->execute();
    $stmt->close();
    admin_audit_log($conn, $adminId, null, null, 'commission_rate_update', $before, $after);
    if (function_exists('sec_log')) {
        sec_log('commission_rate_update', [
            'before_bps' => $before['commission_bps'],
            'after_bps' => $bps,
            'admin_id' => $adminId,
        ]);
    }
    return ['saved' => true, 'bps' => $bps];
}
