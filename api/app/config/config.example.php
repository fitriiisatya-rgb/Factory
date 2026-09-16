<?php
/**
 * Copy this file to config.php (same directory) and fill in real values.
 * config.php is gitignored — NEVER commit real credentials, to this file
 * or anywhere else in the repo.
 *
 * Every key here can also be supplied as an environment variable of the
 * same name (e.g. via cPanel's "Environment Variables" panel, an Apache
 * SetEnv directive, or a process manager). An environment variable always
 * wins over the value in config.php — see ../src/Config.php.
 *
 * ---------------------------------------------------------------------
 * Real cPanel hosting values as of Phase 1 (safe to write here because
 * none of this is a secret — see api/DEPLOY-CPANEL-PREPROD.md):
 *   Domain document root: public_html/factory/  (API lives at
 *                  public_html/factory/api/ — a subfolder of the existing
 *                  Amor Factory frontend's own docroot, not a separate one)
 *   Database:      u7566812_factory
 *   Engine:        MariaDB 10.11.19-cll-lve (OD-4 CLOSED — see
 *                  docs/mysql-open-decisions-v1.md)
 *   Connects via:  localhost (standard cPanel same-host MySQL)
 *   Runtime user:  u7566812_factoryapp — SELECT/INSERT/UPDATE/DELETE only.
 *                  This is DB_USER/DB_PASS below — the day-to-day app
 *                  connection (Database::pdo()) always uses this identity.
 *   Migration user: u7566812_adminfactory — DDL-capable, used ONLY by
 *                  api/_upgrade/ (Database::migrationPdo()), via the
 *                  separate MIGRATION_DB_* keys below. The normal runtime
 *                  connection never uses this identity, ever.
 * The actual passwords are never written here or anywhere in this repo —
 * the human operator fills them into their own untracked config.php.
 * ---------------------------------------------------------------------
 */
return [
    // 'staging' (local/CI disposable instances) or 'preproduction' (real
    // cPanel hosting, schema/seed/auth being verified, not yet live).
    // 'production' is refused outright by ../src/Config.php — this codebase
    // has not been through a production-readiness review.
    'APP_ENV' => 'preproduction',
    'APP_DEBUG' => false,
    'APP_TIMEZONE' => 'Asia/Jakarta',

    // --- Runtime connection (Database::pdo() — everything the API does day to day) ---
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3306',
    'DB_NAME' => 'u7566812_factory',
    'DB_USER' => 'u7566812_factoryapp',
    'DB_PASS' => 'CHANGE_ME', // never commit the real value

    // Safety-gate check: bin/migrate.php AND api/_upgrade/ both refuse to run
    // unless DB_NAME above exactly equals EXPECTED_DB_NAME. This catches a
    // config.php edited to point somewhere else by accident.
    'EXPECTED_DB_NAME' => 'u7566812_factory',

    // --- Migration connection (Database::migrationPdo() — api/_upgrade/ ONLY) ---
    // Schema/DDL only. Leave these four keys OUT ENTIRELY (or blank) when not
    // actively running an upgrade — api/_upgrade/ shows a friendly setup
    // message instead of an error when they're missing, and the normal
    // application never needs them. Add them back only for as long as it
    // takes to click "Terapkan Migrasi" once, then remove MIGRATION_DB_PASS
    // again (the upgrade page reminds you of this after a successful apply).
    'MIGRATION_DB_HOST' => 'localhost',
    'MIGRATION_DB_NAME' => 'u7566812_factory',
    'MIGRATION_DB_USER' => 'u7566812_adminfactory',
    'MIGRATION_DB_PASS' => '', // fill in only while applying a migration, then blank it again

    // Required to use the ../../_setup/index.php web wizard at all (the
    // no-SSH / easy-install path, Phase 0.5 only — already used and
    // deleted on the real deployment). Leave blank once _setup/ is gone.
    'SETUP_TOKEN' => '',

    // Set to false to hard-disable the setup wizard even if SETUP_TOKEN is
    // still present (belt-and-suspenders alongside deleting api/_setup/
    // entirely, which remains the recommended action once setup is done).
    'SETUP_ENABLED' => true,

    // Session cookie hardening (docs/mysql-schema-v1.md §15.1, LOCKED).
    // SESSION_SECURE must be true on any host served over HTTPS (i.e. always,
    // outside of local disposable testing on plain HTTP).
    'SESSION_SECURE' => true,
    'SESSION_SAMESITE' => 'Lax',
    'SESSION_LIFETIME_SECONDS' => 8 * 3600,
];
