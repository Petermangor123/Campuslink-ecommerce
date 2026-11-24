<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/db.php';
function require_login(): void {
    if (!is_logged_in()) {
        set_flash('error', 'Please log in to access that page.');
        redirect(APP_BASE_URL . '/auth/login.php');
    }
}
function require_role($roles): void {
    global $conn;
    require_login();
    $userId = current_user_id();
    $stmt = $conn->prepare('SELECT role, is_active, vendor_status FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account || (int)$account['is_active'] !== 1 || $account['role'] !== current_user_role()) {
        $_SESSION = [];
        session_destroy();
        session_start();
        set_flash('error', 'Your account is no longer active. Please log in again.');
        redirect(APP_BASE_URL . '/auth/login.php');
    }
    if (current_user_role() === 'seller' && ($account['vendor_status'] ?? 'pending') !== 'approved') {
        set_flash('error', 'Vendor operations are available only after administrator approval.');
        redirect(APP_BASE_URL . '/seller/pending.php');
    }
    if (!in_array(current_user_role(), (array)$roles, true)) {
        set_flash('error', 'You do not have permission to view that page.');
        switch (current_user_role()) {
            case 'admin': redirect(APP_BASE_URL . '/admin/dashboard.php');
            case 'seller': redirect(APP_BASE_URL . '/seller/pending.php');
            default: redirect(APP_BASE_URL . '/buyer/dashboard.php');
        }
    }
}
