<?php

declare(strict_types=1);

/**
 * OPTIONAL, TEMPORARY web fallback for bin/create_admin.php — ONLY for
 * cPanel accounts with no SSH/Terminal access (Phase 0.5, section 8).
 *
 * DELETE THIS ENTIRE public/_setup/ DIRECTORY once setup is done.
 * See migrate.php in this same directory for the full security notes
 * (SetupGuard, SETUP_TOKEN) — identical here. The password typed into this
 * form is hashed immediately with password_hash() and is never logged,
 * echoed back, or stored in plaintext anywhere.
 */

require __DIR__ . '/../../autoload.php';

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\SetupGuard;
use Amor\Api\Setup\AdminCreator;
use Amor\Api\Setup\WebRunnerPage;

Config::load();
SetupGuard::authorize();

$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$tokenAttr = htmlspecialchars($token, ENT_QUOTES);

function renderForm(string $tokenAttr, ?string $error = null): void
{
    $errorBlock = $error !== null ? '<p style="color:#b00;font-weight:bold;">' . htmlspecialchars($error) . '</p>' : '';
    echo <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Create/reset admin</title></head>
<body style="font-family:monospace;max-width:640px;margin:2rem auto;">
<h1>Create or reset a staging/preproduction ADMIN user</h1>
{$errorBlock}
<p>The password is hashed with password_hash() immediately and is never logged, echoed back, or stored in plaintext.</p>
<form method="post">
  <input type="hidden" name="token" value="{$tokenAttr}">
  <p><label>Username: <input type="text" name="username" required autocomplete="off"></label></p>
  <p><label>Full name: <input type="text" name="fullName" autocomplete="off"></label></p>
  <p><label>Password (min 10 chars): <input type="password" name="password" required minlength="10" autocomplete="new-password"></label></p>
  <p><label>Confirm password: <input type="password" name="passwordConfirm" required minlength="10" autocomplete="new-password"></label></p>
  <button type="submit">Create / reset</button>
</form>
</body></html>
HTML;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    renderForm($tokenAttr);
    exit;
}

$username = trim((string) ($_POST['username'] ?? ''));
$fullName = trim((string) ($_POST['fullName'] ?? '')) ?: $username;
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['passwordConfirm'] ?? '');

if ($username === '') {
    renderForm($tokenAttr, 'Username is required.');
    exit;
}
if ($password !== $passwordConfirm) {
    renderForm($tokenAttr, 'Passwords did not match.');
    exit;
}

try {
    $pdo = Database::pdo();
    (new AdminCreator($pdo))->createOrReset($username, $fullName, $password);
    WebRunnerPage::result('Create/reset admin', true,
        '<p><code>' . htmlspecialchars($username) . '</code> is now an ADMIN in '
        . htmlspecialchars((string) Config::get('DB_NAME')) . '.</p>');
} catch (\Throwable $e) {
    // AdminCreator's own exceptions never include the password.
    WebRunnerPage::result('Create/reset admin', false, '<p>' . htmlspecialchars($e->getMessage()) . '</p>');
}
