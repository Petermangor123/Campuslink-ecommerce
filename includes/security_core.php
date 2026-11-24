<?php
// CampusLink – security module.
//
// Loaded once from includes/session.php, so every entry point (pages, AJAX
// endpoints, the tracking lookup) gets the same defences:
//   * security response headers + a strict Content-Security-Policy
//   * safe error handling (no stack traces / paths leaked to visitors)
//   * an append-only security event log kept outside the public web root
//   * a small file-backed rate limiter (per IP and per account)
//   * a password policy + hashing helper
//   * HTTP Host header validation
//   * a hardened image upload/re-encode helper
//
// Nothing here changes the visual design or the existing routes.

// ---------------------------------------------------------------------------
// Configuration helpers
// ---------------------------------------------------------------------------

/**
 * Read a config value from the real environment first, then the local .env.
 */
function sec_env(string $key, string $default = ''): string
{
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        return (string)$value;
    }
    static $local = null;
    if ($local === null) {
        $path  = dirname(__DIR__) . '/.env';
        $local = [];
        // Line-based KEY=value loader — avoids PHP parse_ini* breaking on # comments with ().
        if (is_file($path)) {
            $raw = file_get_contents($path);
            if ($raw !== false) {
                foreach (preg_split("/\r\n|\n|\r/", $raw) as $line) {
                    $line = trim($line);
                    if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                        continue;
                    }
                    $eq = strpos($line, '=');
                    if ($eq === false) {
                        continue;
                    }
                    $envKey = trim(substr($line, 0, $eq));
                    if ($envKey === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $envKey)) {
                        continue;
                    }
                    $val = trim(substr($line, $eq + 1));
                    if ($val !== '' && ($val[0] === '"' || $val[0] === "'")) {
                        $q = $val[0];
                        if (str_ends_with($val, $q) && strlen($val) >= 2) {
                            $val = substr($val, 1, -1);
                        }
                    }
                    $local[$envKey] = $val;
                }
            }
        }
    }
    if (isset($local[$key]) && is_string($local[$key]) && $local[$key] !== '') {
        return $local[$key];
    }
    return $default;
}

function sec_bool(string $key, bool $default = false): bool
{
    $raw = sec_env($key, '');
    if ($raw === '') {
        return $default;
    }
    return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
}

/**
 * True when the app is serving a real deployment (strict hardening on).
 * Local hosts are treated as development so local debugging stays usable.
 */
function sec_is_production(): bool
{
    $env = strtolower(sec_env('CAMPUSLINK_ENV', ''));
    if ($env !== '') {
        return !in_array($env, ['local', 'dev', 'development', 'test', 'testing'], true);
    }
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (str_contains($host, ':')) {
        $host = explode(':', $host)[0];
    }
    return !in_array($host, ['localhost', '127.0.0.1', '::1', ''], true);
}

function sec_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    // Only trust a proxy header when the operator has declared a trusted proxy.
    if (sec_bool('CAMPUSLINK_TRUST_PROXY')) {
        if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
            return true;
        }
    }
    return false;
}

/** Best-effort client IP. Forwarded headers are ignored unless a proxy is trusted. */
function sec_client_ip(): string
{
    if (sec_bool('CAMPUSLINK_TRUST_PROXY')) {
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff !== '') {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
        $real = (string)($_SERVER['HTTP_X_REAL_IP'] ?? '');
        if (filter_var($real, FILTER_VALIDATE_IP)) {
            return $real;
        }
    }
    $addr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return $addr !== '' ? $addr : '0.0.0.0';
}

// ---------------------------------------------------------------------------
// Private storage (logs + rate-limit state). Lives under /storage, which is
// denied from the web (see storage/.htaccess and the root .htaccess).
// ---------------------------------------------------------------------------

function sec_storage_dir(string $sub): string
{
    $dir = dirname(__DIR__) . '/storage/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir;
}

// ---------------------------------------------------------------------------
// Security event logging. Append-only, never contains passwords/tokens/cards.
// ---------------------------------------------------------------------------

function sec_log(string $event, array $context = []): void
{
    $line = json_encode([
        'time'   => date('c'),
        'event'  => $event,
        'ip'     => sec_client_ip(),
        'actor'  => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
        'role'   => $_SESSION['user_role'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'uri'    => substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 300),
        'ctx'    => $context,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    @file_put_contents(
        sec_storage_dir('logs') . '/security.log',
        $line . "\n",
        FILE_APPEND | LOCK_EX
    );
}

// ---------------------------------------------------------------------------
// Error handling: log privately, never display internals to visitors.
// ---------------------------------------------------------------------------

function sec_render_error_page(int $code): void
{
    if (headers_sent()) {
        return;
    }
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $title = $code === 403 ? 'Access denied' : ($code === 404 ? 'Not found' : 'Something went wrong');
    $body  = $code === 403
        ? 'You do not have permission to view this page.'
        : ($code === 404
            ? 'We could not find the page you were looking for.'
            : 'We hit an unexpected problem. Please try again in a moment.');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<meta name="robots" content="noindex">'
       . '<title>' . $code . ' · CampusLink</title>'
       . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#F6F4EF;color:#123C32;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:2rem}'
       . '.box{max-width:30rem;text-align:center}h1{font-size:3rem;margin:0 0 .5rem}p{color:#54615c;line-height:1.6}'
       . 'a{display:inline-block;margin-top:1.5rem;color:#123C32;font-weight:600}</style></head><body><div class="box">'
       . '<h1>' . $code . '</h1><p><strong>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong></p>'
       . '<p>' . htmlspecialchars($body, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<a href="' . htmlspecialchars((defined('APP_BASE_URL') && APP_BASE_URL !== '') ? APP_BASE_URL . '/' : '/CampusLink_Gr_17/', ENT_QUOTES, 'UTF-8') . '">Return to CampusLink</a></div></body></html>';
    exit;
}

function sec_init_errors(): void
{
    error_reporting(E_ALL);
    if (sec_is_production()) {
        @ini_set('display_errors', '0');
        @ini_set('display_startup_errors', '0');
    }
    @ini_set('log_errors', '1');
    @ini_set('error_log', sec_storage_dir('logs') . '/php-error.log');

    set_exception_handler(static function (Throwable $e): void {
        sec_log('uncaught_exception', [
            'class'   => get_class($e),
            'message' => $e->getMessage(),
            'file'    => $e->getFile(),
            'line'    => $e->getLine(),
        ]);
        sec_render_error_page(500);
    });

    register_shutdown_function(static function (): void {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            sec_log('fatal_error', [
                'message' => $err['message'],
                'file'    => $err['file'],
                'line'    => $err['line'],
            ]);
            sec_render_error_page(500);
        }
    });
}

// Send the security headers, including Content-Security-Policy.
function sec_send_headers(): void
{
    if (headers_sent()) {
        return;
    }

    @ini_set('expose_php', '0');
    header_remove('X-Powered-By');
    header_remove('Server');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
    header('X-Permitted-Cross-Domain-Policies: none');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');

    // Google Fonts + inline style="" attributes need style-src 'unsafe-inline'.
    // script-src stays strict ('self' only) — page behaviour lives in assets/js.
    // form-action MUST allow Paystack hosted checkout: after POST, browsers
    // (notably Chromium) enforce form-action on the redirect target. With only
    // 'self', the 302 to checkout.paystack.com is blocked, the first click
    // appears to do nothing, and a second click hits the same-origin receipt
    // redirect (Order received) instead of Paystack.
    $csp = "default-src 'self'; "
         . "base-uri 'self'; "
         . "form-action 'self' https://checkout.paystack.com https://paystack.com; "
         . "frame-ancestors 'none'; "
         . "object-src 'none'; "
         . "img-src 'self' data: blob:; "
         . "font-src 'self' https://fonts.gstatic.com; "
         . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
         . "script-src 'self'; "
         . "connect-src 'self'; "
         . "manifest-src 'self'";
    // Only force HTTPS upgrades once TLS is confirmed, otherwise local HTTP breaks.
    if (sec_is_https()) {
        $csp .= "; upgrade-insecure-requests";
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('Content-Security-Policy: ' . $csp);
}

// ---------------------------------------------------------------------------
// Host header validation (prevents Host-header injection / cache poisoning).
// ---------------------------------------------------------------------------

/** @return string[] Lower-cased allowed hosts. A leading dot allows subdomains. */
function sec_allowed_hosts(): array
{
    $raw   = sec_env('CAMPUSLINK_ALLOWED_HOSTS', '');
    $hosts = [];
    if ($raw !== '') {
        foreach (preg_split('/[\s,]+/', $raw) as $h) {
            $h = strtolower(trim($h));
            if ($h !== '') {
                $hosts[] = $h;
            }
        }
    }
    if (!$hosts) {
        $hosts = ['localhost', '127.0.0.1', '::1'];
    }
    return $hosts;
}

function sec_valid_host(?string $host): bool
{
    if ($host === null || $host === '') {
        return false;
    }
    $host = strtolower(trim($host));
    if (preg_match('/^\[(.+)\](?::\d+)?$/', $host, $m)) {
        $host = $m[1]; // IPv6 literal
    } elseif (substr_count($host, ':') === 1) {
        $host = explode(':', $host)[0];
    }

    $allowed = sec_allowed_hosts();
    if (in_array('*', $allowed, true) || in_array($host, $allowed, true)) {
        return true;
    }
    foreach ($allowed as $a) {
        if (str_starts_with($a, '.') && str_ends_with($host, $a)) {
            return true; // e.g. ".example.com" allows shop.example.com
        }
    }
    return false;
}

/**
 * Reject requests whose Host header is not on the allow-list.
 * Opt-in (CAMPUSLINK_ENFORCE_HOST=1) so existing local/staging access is not
 * broken before the real host names are known.
 */
function sec_enforce_host(): void
{
    if (!sec_bool('CAMPUSLINK_ENFORCE_HOST')) {
        return;
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!sec_valid_host($host)) {
        sec_log('host_header_rejected', ['host' => $host]);
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        exit('Bad request.');
    }
}

// ---------------------------------------------------------------------------
// Rate limiting (file-backed, per IP and per account). Fails open on I/O error
// so a disk problem cannot take the site down.
// ---------------------------------------------------------------------------

/**
 * Registration abuse limit per client IP (customer + vendor share one bucket).
 * Sized for shared campus NAT / public Wi-Fi while still slowing mass sign-ups.
 * Only completed attempts (passed form validation) consume the budget.
 */
const REGISTER_IP_MAX = 40;
const REGISTER_IP_WINDOW = 3600; // 1 hour

/**
 * Record (optionally just read) a hit against a bucket.
 *
 * @return array{limited:bool,count:int,retry:int}
 */
function sec_rate(string $bucket, int $max, int $windowSeconds, bool $increment = true): array
{
    $file = sec_storage_dir('ratelimit') . '/' . hash('sha256', $bucket) . '.json';
    $now  = time();
    $data = ['start' => $now, 'count' => 0];

    $fh = @fopen($file, 'c+');
    if ($fh === false) {
        return ['limited' => false, 'count' => 0, 'retry' => 0];
    }
    if (flock($fh, LOCK_EX)) {
        $parsed = json_decode((string)stream_get_contents($fh), true);
        if (is_array($parsed) && isset($parsed['start'], $parsed['count'])
            && ($now - (int)$parsed['start']) < $windowSeconds) {
            $data = ['start' => (int)$parsed['start'], 'count' => (int)$parsed['count']];
        }
        if ($increment) {
            $data['count']++;
        }
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($data));
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);

    return [
        'limited' => $data['count'] > $max,
        'count'   => $data['count'],
        'retry'   => max(0, $windowSeconds - ($now - (int)$data['start'])),
    ];
}

function sec_rate_clear(string $bucket): void
{
    $file = sec_storage_dir('ratelimit') . '/' . hash('sha256', $bucket) . '.json';
    if (is_file($file)) {
        @unlink($file);
    }
}

// ---------------------------------------------------------------------------
// Password hashing + policy.
// ---------------------------------------------------------------------------

function sec_password_algo(): string
{
    return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
}

function sec_hash_password(string $password): string
{
    $algo = sec_password_algo();
    try {
        $hash = password_hash($password, $algo);
    } catch (Throwable $e) {
        $hash = false;
    }
    if (!is_string($hash) || $hash === '') {
        $hash = password_hash($password, PASSWORD_BCRYPT);
    }
    return $hash;
}

function sec_password_needs_rehash(string $hash): bool
{
    return password_needs_rehash($hash, sec_password_algo());
}

/** A valid bcrypt hash used to equalise login timing for unknown accounts. */
function sec_dummy_hash(): string
{
    return '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
}

/**
 * Shared password policy for registration and password changes.
 *
 * @return string|null Human-readable reason the password is unacceptable, or null.
 */
function sec_password_error(string $password, string $email = '', string $name = ''): ?string
{
    $length = strlen($password);
    if ($length < 12) {
        return 'Password must be at least 12 characters.';
    }
    if ($length > 200) {
        return 'Password is too long (200 characters maximum).';
    }
    if (preg_match('/^[0-9]+$/', $password)) {
        return 'Password cannot contain only numbers. Use letters and at least one symbol.';
    }

    $lower = strtolower($password);
    if (preg_match('/(.)\1{3,}/', $lower) || preg_match('/(.{2})\1{2,}/', $lower)) {
        return 'Password cannot contain repeated characters or repeated patterns.';
    }
    for ($i = 0, $last = $length - 3; $i < $last; $i++) {
        $ascending = true;
        $descending = true;
        for ($j = 1; $j < 4; $j++) {
            $difference = ord($lower[$i + $j]) - ord($lower[$i + $j - 1]);
            $ascending = $ascending && $difference === 1;
            $descending = $descending && $difference === -1;
        }
        if ($ascending || $descending) {
            return 'Password cannot contain sequential characters such as 1234 or abcd.';
        }
    }

    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must include at least one lowercase letter.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must include at least one uppercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must include at least one number.';
    }
    if (!preg_match('/[^A-Za-z0-9\s]/', $password)) {
        return 'Password must include at least one symbol, such as !, @, #, or $.';
    }

    foreach (sec_common_passwords() as $blocked) {
        if ($lower === $blocked) {
            return 'That password is too common. Please choose something less predictable.';
        }
    }
    $commonStems = [
        'password', 'passw0rd', 'qwerty', 'letmein', 'welcome', 'admin',
        'campuslink', 'student', 'iloveyou', 'changeme', 'dragon',
        'football', 'baseball', 'sunshine', 'princess',
    ];
    foreach ($commonStems as $stem) {
        if (str_starts_with($lower, $stem) && preg_match('/^[0-9!@#$%^&*._?+\-]*$/', substr($lower, strlen($stem)))) {
            return 'That password is too common. Please choose something less predictable.';
        }
    }

    $emailLower = strtolower(trim($email));
    if ($emailLower !== '' && str_contains($lower, $emailLower)) {
        return 'Password must not contain your email address.';
    }
    $nameLower = strtolower(trim($name));
    if (strlen($nameLower) >= 3 && str_contains($lower, $nameLower)) {
        return 'Password must not contain your name.';
    }

    return null;
}

/** @return list<string> Lowercase passwords that are never accepted. */
function sec_common_passwords(): array
{
    return [
        'password', 'password1', 'password123', 'passw0rd',
        '12345678', '123456789', '1234567890',
        'qwerty123', 'qwertyui', 'qwertyuiop',
        'iloveyou', 'admin123', 'letmein', 'welcome1',
        'abc12345', '11111111', 'changeme',
        'football', 'baseball', 'sunshine', 'princess',
        'dragon123', 'campuslink', 'student1',
    ];
}

// ---------------------------------------------------------------------------
// Image upload hardening: validate, then re-encode through GD to strip EXIF
// metadata and any payload smuggled inside the image container.
// ---------------------------------------------------------------------------

/** Default max product image size (5 MB). Must stay in sync with assets/js/app.js. */
const SEC_IMAGE_MAX_BYTES = 5242880;

/**
 * Validate and store an uploaded image under $destDir.
 *
 * @param array       $file     A single entry from $_FILES.
 * @param string      $destDir  Absolute destination directory (trailing slash optional).
 * @param string|null $error    Set to a user-safe message on failure.
 * @return string|null Random file name (not full path) on success, else null.
 */
function sec_store_image(array $file, string $destDir, ?string &$error = null, int $maxBytes = SEC_IMAGE_MAX_BYTES, int $maxDim = 4000): ?string
{
    $error = null;
    if ($maxBytes <= 0) {
        $maxBytes = SEC_IMAGE_MAX_BYTES;
    }
    $maxMbLabel = (string)(int)round($maxBytes / 1048576);

    $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($errCode !== UPLOAD_ERR_OK || !isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            $error = 'Image must be under ' . $maxMbLabel . ' MB.';
        } elseif ($errCode === UPLOAD_ERR_NO_FILE) {
            $error = 'No image was uploaded.';
        } else {
            $error = 'The image could not be uploaded. Please try again.';
        }
        return null;
    }

    $tmp = $file['tmp_name'];

    // Real MIME type (never trust the browser-supplied type or the extension).
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $tmp) : false;
    if ($finfo) {
        finfo_close($finfo);
    }
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!is_string($mime) || !isset($allowed[$mime])) {
        $error = 'Image must be JPEG, PNG, WebP or GIF.';
        return null;
    }

    if (($file['size'] ?? 0) > $maxBytes) {
        $error = 'Image must be under ' . $maxMbLabel . ' MB.';
        return null;
    }

    $info = @getimagesize($tmp);
    if ($info === false || empty($info[0]) || empty($info[1])) {
        $error = 'That file is not a valid image.';
        return null;
    }
    [$width, $height] = [(int)$info[0], (int)$info[1]];
    if ($width < 1 || $height < 1 || $width > $maxDim || $height > $maxDim) {
        $error = 'Image dimensions are too large (maximum ' . $maxDim . ' × ' . $maxDim . ' pixels).';
        return null;
    }

    if (!function_exists('imagecreatefromjpeg')) {
        $error = 'Image processing is unavailable on this server.';
        return null;
    }

    $source = sec_image_create($tmp, $mime);
    if ($source === null) {
        $error = 'That image could not be processed.';
        return null;
    }

    // GIF is re-encoded as PNG; everything else keeps its (safe) container.
    $outMime   = $mime === 'image/gif' ? 'image/png' : $mime;
    $extension = $outMime === 'image/png' ? 'png' : ($outMime === 'image/webp' ? 'webp' : 'jpg');
    $name      = bin2hex(random_bytes(16)) . '.' . $extension;

    $destDir = rtrim($destDir, '/\\') . '/';
    if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
        $error = 'Unable to create the image upload directory.';
        imagedestroy($source);
        return null;
    }
    if (!is_writable($destDir)) {
        $error = 'The image upload directory is not writable. Please check folder permissions.';
        imagedestroy($source);
        return null;
    }

    $ok = sec_image_save($source, $destDir . $name, $outMime);
    imagedestroy($source);
    if (!$ok) {
        // GD re-encoding failed (e.g. missing WebP support, memory limit).
        // The file has already been validated as a real image above, so fall
        // back to copying the validated upload directly rather than failing.
        $ok = @move_uploaded_file($tmp, $destDir . $name)
           || @copy($tmp, $destDir . $name);
    }
    if (!$ok) {
        @unlink($destDir . $name);
        $error = 'Unable to save the uploaded image.';
        return null;
    }
    return $name;
}

function sec_image_create(string $path, string $mime)
{
    try {
        switch ($mime) {
            case 'image/jpeg':
                return @imagecreatefromjpeg($path) ?: null;
            case 'image/png':
                return @imagecreatefrompng($path) ?: null;
            case 'image/webp':
                return function_exists('imagecreatefromwebp') ? (@imagecreatefromwebp($path) ?: null) : null;
            case 'image/gif':
                return @imagecreatefromgif($path) ?: null;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

function sec_image_save($source, string $dest, string $mime): bool
{
    $width  = imagesx($source);
    $height = imagesy($source);
    if ($width < 1 || $height < 1) {
        return false;
    }

    // Draw onto a fresh canvas — this discards EXIF and any appended payload.
    $canvas = imagecreatetruecolor($width, $height);
    if (!$canvas) {
        return false;
    }
    if ($mime === 'image/jpeg') {
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $white);
    } else {
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
    }
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $width, $height);

    switch ($mime) {
        case 'image/png':
            $ok = imagepng($canvas, $dest, 6);
            break;
        case 'image/webp':
            $ok = function_exists('imagewebp') ? imagewebp($canvas, $dest, 82) : false;
            break;
        default:
            $ok = imagejpeg($canvas, $dest, 85);
            break;
    }
    imagedestroy($canvas);
    return (bool)$ok;
}
