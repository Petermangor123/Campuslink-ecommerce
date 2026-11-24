<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (in_array('--help', $argv, true)) {
    fwrite(STDOUT, "Usage: php scripts/create_admin.php <email> [name]\n");
    exit(0);
}

$email = trim((string)($argv[1] ?? ''));
$name = trim((string)($argv[2] ?? 'Administrator'));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    fwrite(STDERR, "Provide a valid email address.\n");
    exit(1);
}

if ($name === '' || strlen($name) > 100) {
    fwrite(STDERR, "Name must be between 1 and 100 characters.\n");
    exit(1);
}

require_once __DIR__ . '/../config/db.php';

try {
    $existing = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $existing->bind_param('s', $email);
    $existing->execute();
    $existingResult = $existing->get_result();
    $exists = $existingResult->num_rows > 0;
    $existingResult->free();
    $existing->close();

    if ($exists) {
        fwrite(STDERR, "An account with that email already exists. No changes were made.\n");
        exit(1);
    }

    $password = 'CL!' . bin2hex(random_bytes(12));
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $insert = $conn->prepare(
        "INSERT INTO users (name, email, password, role, is_active) VALUES (?, ?, ?, 'admin', 1)"
    );
    $insert->bind_param('sss', $name, $email, $passwordHash);

    if (!$insert->execute()) {
        throw new RuntimeException($insert->error);
    }

    $insert->close();

    fwrite(STDOUT, "Admin account created.\n");
    fwrite(STDOUT, "Email: {$email}\n");
    fwrite(STDOUT, "Password: {$password}\n");
    fwrite(STDOUT, "Delete this script after signing in: del scripts\\create_admin.php\n");
} catch (Throwable $e) {
    error_log('CampusLink admin creation failed: ' . $e->getMessage());
    fwrite(STDERR, "Admin creation failed. Check storage/logs/php-error.log.\n");
    exit(1);
}
