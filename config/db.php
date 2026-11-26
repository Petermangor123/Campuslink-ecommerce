<?php
/**
 * CampusLink database connection.
 * Credentials are read from the environment or the project .env file.
 * This directory is denied to HTTP clients by config/.htaccess.
 */
require_once __DIR__ . '/../includes/security_core.php';

$host = sec_env('CAMPUSLINK_DB_HOST', 'localhost');
$dbUser = sec_env('CAMPUSLINK_DB_USER', 'root');
$dbPass = sec_env('CAMPUSLINK_DB_PASS', '');
$database = sec_env('CAMPUSLINK_DB_NAME', 'ecommerce_2026A_chol_thiong');
$port = (int)sec_env('CAMPUSLINK_DB_PORT', '3306');
if ($port < 1 || $port > 65535) {
    $port = 3306;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $dbUser, $dbPass, $database, $port);
} catch (mysqli_sql_exception $e) {
    error_log('CampusLink database connection error: ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, "Database connection failed.\n");
        exit(1);
    }
    http_response_code(500);
    exit('Database connection failed.');
}

$conn->set_charset('utf8mb4');
