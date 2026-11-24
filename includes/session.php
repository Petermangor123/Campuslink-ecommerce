<?php
// session + helpers
require_once __DIR__ . '/security_core.php';

// Work out the site folder so the session cookie stays inside this project.
if (!defined('APP_BASE_URL')) {
    // 1. Explicit override from .env (needed for Apache userdir ~user/... setups
    //    where DOCUMENT_ROOT does not contain the app path).
    $detected = '';
    $envPath = dirname(__DIR__) . '/.env';
    if (is_file($envPath)) {
        foreach (preg_split("/\r\n|\n|\r/", (string)file_get_contents($envPath)) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }
            if (strpos($line, 'CAMPUSLINK_BASE_URL=') === 0) {
                $val = trim(substr($line, strlen('CAMPUSLINK_BASE_URL=')));
                if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                    $q = $val[0];
                    if (str_ends_with($val, $q) && strlen($val) >= 2) {
                        $val = substr($val, 1, -1);
                    }
                }
                $detected = $val;
                break;
            }
        }
    }

    // 2. Auto-detect from DOCUMENT_ROOT (works when the app lives under the
    //    document root, e.g. local XAMPP htdocs).
    if ($detected === '') {
        $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
        $appRoot = realpath(dirname(__DIR__));
        if ($docRoot && $appRoot) {
            $docRoot = str_replace('\\', '/', $docRoot);
            $appRoot = str_replace('\\', '/', $appRoot);
            if (stripos($appRoot, $docRoot) === 0) {
                $relative = substr($appRoot, strlen($docRoot));
                $segments = array_values(array_filter(explode('/', $relative), static fn(string $part): bool => $part !== ''));
                $detected = $segments ? '/' . implode('/', array_map('rawurlencode', $segments)) : '';
            }
        }
    }

    // 3. Fallback: derive from SCRIPT_NAME (handles userdir / non-doc-root
    //    deployments where DOCUMENT_ROOT detection fails). Strip the script
    //    filename and any subdirectory the entry script sits in.
    if ($detected === '') {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script !== '') {
            // Known entry points relative to app root.
            $candidates = [
                '/index.php',
                '/public/index.php',
                '/public/browse.php',
                '/public/track.php',
            ];
            foreach ($candidates as $cand) {
                if (str_ends_with($script, $cand)) {
                    $detected = rtrim(substr($script, 0, -strlen($cand)), '/');
                    break;
                }
            }
            // Generic fallback: strip everything after the last known app segment.
            if ($detected === '' && strpos($script, 'CampusLink_Gr_17') !== false) {
                $pos = strpos($script, 'CampusLink_Gr_17');
                $detected = rtrim(substr($script, 0, $pos + strlen('CampusLink_Gr_17')), '/');
            }
        }
    }

    define('APP_BASE_URL', rtrim($detected !== '' ? $detected : '/CampusLink_Gr_17', '/'));
}

// Turn on error handling and security headers before any page output.
sec_init_errors();
sec_send_headers();
sec_enforce_host();

if (!defined('CL_SESSION_IDLE_SECONDS'))     define('CL_SESSION_IDLE_SECONDS', 1800);    // 30 min idle
if (!defined('CL_SESSION_ABSOLUTE_SECONDS')) define('CL_SESSION_ABSOLUTE_SECONDS', 43200); // 12 h absolute

if (session_status() === PHP_SESSION_NONE) {
    // Cookie-backed sessions only; reject unknown/attacker-supplied session ids.
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.use_trans_sid', '0');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => APP_BASE_URL !== '' ? APP_BASE_URL . '/' : '/',
        'domain'   => '',
        'secure'   => sec_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Drop an expired session and start a clean one that carries the notice. */
function session_enforce_timeouts(): void
{
    if (!is_logged_in()) {
        return;
    }
    $now = time();
    if (!isset($_SESSION['created_at']))     $_SESSION['created_at']     = $now;
    if (!isset($_SESSION['last_activity']))  $_SESSION['last_activity']  = $now;

    $idle = $now - (int)$_SESSION['last_activity'];
    $age  = $now - (int)$_SESSION['created_at'];

    if ($idle > CL_SESSION_IDLE_SECONDS || $age > CL_SESSION_ABSOLUTE_SECONDS) {
        sec_log('session_timeout', ['idle' => $idle, 'age' => $age]);
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        session_start();
        session_regenerate_id(true);
        set_flash('error', 'Your session timed out for security. Please sign in again.');
        return;
    }
    $_SESSION['last_activity'] = $now;
}
session_enforce_timeouts();

function is_logged_in(): bool { return isset($_SESSION['user_id']); }
function current_user_role(): string { return $_SESSION['user_role'] ?? ''; }
function current_user_id(): int { return (int)($_SESSION['user_id'] ?? 0); }
function current_user_name(): string { return $_SESSION['user_name'] ?? 'Guest'; }
function redirect(string $url): void { header('Location: ' . $url); exit; }
function set_flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}
function get_flash(): ?array {
    if (!isset($_SESSION['flash'])) return null;
    $flash = $_SESSION['flash']; unset($_SESSION['flash']); return $flash;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf_token'];
}

/** True when the request looks same-origin (Origin/Referer check, second CSRF layer). */
function same_origin_request(): bool
{
    $hostHeader = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($hostHeader === '') {
        return true; // cannot verify (non-browser client); the token check still applies
    }
    $host = $hostHeader;
    $port = '';
    if (str_contains($host, ':')) {
        [$host, $port] = explode(':', $host, 2);
    }

    $sources = array_values(array_filter([
        (string)($_SERVER['HTTP_ORIGIN'] ?? ''),
        (string)($_SERVER['HTTP_REFERER'] ?? ''),
    ], static fn(string $s): bool => $s !== ''));
    if (!$sources) {
        return true; // browser sent neither; do not hard-fail
    }

    foreach ($sources as $src) {
        $h = parse_url($src, PHP_URL_HOST);
        if ($h === null) {
            continue;
        }
        $p = parse_url($src, PHP_URL_PORT);
        $p = $p ? (string)$p : '';
        if ($p === '80' || $p === '443') {
            $p = '';
        }
        if (strtolower($h) === $host && $p === $port) {
            return true;
        }
    }
    return false;
}

function verify_csrf(): void {
    $sent = $_POST['csrf_token'] ?? '';
    $tokenOk  = is_string($sent) && hash_equals(csrf_token(), $sent);
    $originOk = same_origin_request();
    if (!$tokenOk || !$originOk) {
        sec_log('csrf_failure', ['token_ok' => $tokenOk, 'origin_ok' => $originOk]);
        http_response_code(419);
        exit('Your form session expired. Go back, refresh the page, and try again.');
    }
}
function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function safe_local_path(string $path): string {
    $path = trim($path);
    if ($path === '' || str_contains($path, '://') || str_contains($path, '\\') || str_contains($path, "\n") || str_contains($path, "\r")) {
        return '';
    }
    if (!str_starts_with($path, APP_BASE_URL . '/')) {
        return '';
    }
    return $path;
}

require_once __DIR__ . '/currency.php';

/** @see format_currency() in currency.php — GHC display; ISO GHS for Paystack. */
