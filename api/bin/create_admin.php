<?php

declare(strict_types=1);

/**
 * Creates (or resets the password of) a staging/preproduction ADMIN user.
 * Never hardcodes a password in source — reads it interactively (terminal
 * echo disabled where possible) or from the ADMIN_PASSWORD environment
 * variable for non-interactive/CI use. Core logic lives in
 * src/Setup/AdminCreator.php, shared with the optional
 * public/_setup/create_admin.php web fallback.
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
 * procedure (see api/DEPLOY.md / api/DEPLOY-CPANEL-PREPROD.md).
 */

require __DIR__ . '/../autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Setup\AdminCreator;

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

Config::load();
$pdo = Database::pdo();

try {
    (new AdminCreator($pdo))->createOrReset($username, $fullName, $password);
} catch (\Throwable $e) {
    // Never a password in this message — AdminCreator's own exceptions never include it.
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
