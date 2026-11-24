<?php
// Compatibility: Sellers directory merged into Vendor Management.
require_once __DIR__ . '/../includes/auth_guard.php';
require_role('admin');
redirect(APP_BASE_URL . '/admin/vendors.php');
