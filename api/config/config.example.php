<?php
/**
 * Copy this file to config.php (same directory) and fill in real staging
 * values. config.php is gitignored — never commit real credentials.
 *
 * Every key here can also be supplied as an environment variable of the
 * same name (e.g. via cPanel's "Environment Variables" panel, an Apache
 * SetEnv directive, or a process manager). An environment variable always
 * wins over the value in config.php — see src/Config.php.
 */
return [
    // 'staging' or 'production'. Phase 0 must only ever run as 'staging'.
    'APP_ENV' => 'staging',
    'APP_DEBUG' => false,
    'APP_TIMEZONE' => 'Asia/Jakarta',

    'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '3306',
    'DB_NAME' => 'amor_factory_staging',
    'DB_USER' => 'amor_staging_app',
    'DB_PASS' => 'CHANGE_ME',

    // Session cookie hardening (docs/mysql-schema-v1.md §15.1, LOCKED).
    // SESSION_SECURE must be true on any host served over HTTPS (i.e. always,
    // outside of local disposable testing on plain HTTP).
    'SESSION_SECURE' => true,
    'SESSION_SAMESITE' => 'Lax',
    'SESSION_LIFETIME_SECONDS' => 8 * 3600,
];
