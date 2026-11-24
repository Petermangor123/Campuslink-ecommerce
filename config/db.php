<?php
// db connection, reads .env (xampp defaults if it's missing)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$localConfigPath = dirname(__DIR__) . '/.env';
// Line-based KEY=value loader — avoids PHP parse_ini* breaking on # comments with ().
$campuslinkLoadEnv = static function (string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $out = [];
    foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        if ($key === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            continue;
        }
        $val = trim(substr($line, $eq + 1));
        if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
            $q = $val[0];
            if (str_ends_with($val, $q) && strlen($val) >= 2) {
                $val = substr($val, 1, -1);
            }
        }
        $out[$key] = $val;
    }
    return $out;
};
$localConfig = $campuslinkLoadEnv($localConfigPath);
if ($localConfig === [] && is_file($localConfigPath) && filesize($localConfigPath) > 0) {
    error_log('Unable to parse the CampusLink .env configuration file.');
    http_response_code(500);
    exit('Unable to read the CampusLink database configuration.');
}

$setting = static function (string $name, string $default = '') use ($localConfig): string {
    $environmentValue = getenv($name);
    if ($environmentValue !== false) {
        return $environmentValue;
    }
    $localValue = $localConfig[$name] ?? $default;
    return is_string($localValue) ? $localValue : $default;
};

$host     = $setting('CAMPUSLINK_DB_HOST', 'localhost');
$username = $setting('CAMPUSLINK_DB_USER', 'root');
$password = $setting('CAMPUSLINK_DB_PASS', '');
$database = $setting('CAMPUSLINK_DB_NAME', 'ecommerce_2026A_chol_thiong');
$port     = (int)($setting('CAMPUSLINK_DB_PORT', '3306') ?: 3306);

try {
    $conn = new mysqli($host, $username, $password, $database, $port);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('CampusLink database connection error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to connect to the CampusLink database. Make sure MySQL is running and the configured database exists.');
}
