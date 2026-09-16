<?php
/**
 * Copy this file to config.php (same directory) and fill in real values.
 * config.php is gitignored — NEVER commit real credentials, to this file
 * or anywhere else in the repo.
 *
 * Every key here can also be supplied as an environment variable of the
 * same name (e.g. via cPanel's "Environment Variables" panel, an Apache
 * SetEnv directive, or a process manager). An environment variable always
 * wins over the value in config.php — see src/Config.php.
 *
 * ---------------------------------------------------------------------
 * Real cPanel hosting values confirmed as of Phase 0.5 (safe to write here
 * because none of this is a secret — see api/DEPLOY-CPANEL-PREPROD.md):
 *   Database:      u7566812_factory   (currently EMPTY / newly created)
 *   Engine:        MariaDB 10.11.19-cll-lve (OD-4 CLOSED — see
 *                  docs/mysql-open-decisions-v1.md)
 *   Connects via:  localhost (standard cPanel same-host MySQL)
 *   Migration user: u7566812_adminfactory (schema/DDL only — see section
 *                  6 below and api/DEPLOY-CPANEL-PREPROD.md; this is NOT
 *                  the user the running application should use)
 * The actual password for either user is never written here or anywhere
 * in this repo — the human operator fills DB_PASS into their own
 * untracked config.php on the server.
 * ---------------------------------------------------------------------
 */
return [
    // 'staging' (local/CI disposable instances) or 'preproduction' (real
    // cPanel hosting, schema/seed/auth being verified, not yet live).
    // 'production' is refused outright by src/Config.php — this codebase
    // has not been through a production-readiness review.
    'APP_ENV' => 'preproduction',
    'APP_DEBUG' => false,
    'APP_TIMEZONE' => 'Asia/Jakarta',

    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'u7566812_factory',

    // Safety-gate check (Phase 0.5, section 3): bin/migrate.php refuses to run
    // unless DB_NAME above exactly equals EXPECTED_DB_NAME. This catches a
    // config.php edited to point somewhere else by accident. Keep both in
    // sync deliberately — they are not meant to ever silently differ.
    'EXPECTED_DB_NAME' => 'u7566812_factory',

    // Schema/DDL setup only (CREATE/ALTER/INDEX/FK). Used by bin/migrate.php
    // and nothing else. The running application must NOT use this identity
    // once section 6's runtime user exists — see api/DEPLOY-CPANEL-PREPROD.md.
    'DB_USER' => 'u7566812_adminfactory',
    'DB_PASS' => 'CHANGE_ME', // never commit the real value

    // Session cookie hardening (docs/mysql-schema-v1.md §15.1, LOCKED).
    // SESSION_SECURE must be true on any host served over HTTPS (i.e. always,
    // outside of local disposable testing on plain HTTP).
    'SESSION_SECURE' => true,
    'SESSION_SAMESITE' => 'Lax',
    'SESSION_LIFETIME_SECONDS' => 8 * 3600,
];
