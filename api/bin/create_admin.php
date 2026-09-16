<?php

declare(strict_types=1);

/**
 * Creates (or resets the password of) a staging ADMIN user. Never hardcodes
 * a password in source — reads it interactively (terminal echo disabled
 * where possible) or from the ADMIN_PASSWORD environment variable for
 * non-interactive/CI use.
 *
 * Usage:
 *   php api/bin/create_admin.php <username> [full name...]
 *   -> prompts for password (hidden) if ADMIN_PASSWORD is not set
 *
 *   ADMIN_PASSWORD='...' php api/bin/create_admin.php <username> [full name...]
 *   -> non-interactive; set the var in your shell, not in a committed file
 *
 * Re-running with an existing username resets that user's password and
 * reactivates the account — this IS the documented "reset staging admin"
 * procedure (see api/DEPLOY.md).
 */

require __DIR__ . '/../autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;

$username = $argv[1] ?? null;
if ($username === null || trim($username) === '') {
    fwrite(STDERR, "Usage: php api/bin/create_admin.php <username> [full name...]\n");
    exit(1);
}
$fullName = trim(implode(' ', array_slice($argv, 2))) ?: $username;

$password = getenv('ADMIN_PASSWORD');
if ($password === false || $password === '') {
    $password = readHiddenPassword('Password for ' . $username . ': ');
    $confirm = readHiddenPassword('Confirm password: ');
    if ($password !== $confirm) {
        fwrite(STDERR, "Passwords did not match.\n");
        exit(1);
    }
}
if (strlen($password) < 10) {
    fwrite(STDERR, "Password must be at least 10 characters.\n");
    exit(1);
}

Config::load();
$pdo = Database::pdo();

$hash = password_hash($password, PASSWORD_DEFAULT);

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        'INSERT INTO users (username, password_hash, full_name, active, created_at)
         VALUES (?, ?, ?, 1, UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), full_name = VALUES(full_name), active = 1, updated_at = UTC_TIMESTAMP()'
    );
    $stmt->execute([$username, $hash, $fullName]);

    $userId = (int) $pdo->query('SELECT user_id FROM users WHERE username = ' . $pdo->quote($username))->fetchColumn();

    $roleId = (int) $pdo->query("SELECT role_id FROM roles WHERE code = 'ADMIN'")->fetchColumn();
    if ($roleId === 0) {
        throw new RuntimeException("ADMIN role not found — run 'php api/bin/seed.php' first.");
    }

    $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Failed: ' . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "OK: {$username} is now an ADMIN in " . Config::get('DB_NAME') . "\n");

function readHiddenPassword(string $prompt): string
{
    fwrite(STDOUT, $prompt);
    if (stripos(PHP_OS, 'WIN') === 0) {
        // No portable hidden-input on Windows CLI without extra tooling; fall back to visible input.
        return trim((string) fgets(STDIN));
    }
    system('stty -echo');
    $password = trim((string) fgets(STDIN));
    system('stty echo');
    fwrite(STDOUT, "\n");
    return $password;
}
