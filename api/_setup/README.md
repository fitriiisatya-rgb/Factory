# TEMPORARY — delete this whole directory after setup

This is a single guided setup wizard (`index.php`) for the initial
database install. It must not live permanently on any reachable host.

It refuses to run unless:
1. `APP_ENV` is not `production`/`live`.
2. It hasn't been disabled (a `.disabled` marker file here, or
   `SETUP_ENABLED => false` in config — the "Selesai & Nonaktifkan Setup"
   button on the page itself writes the marker file).
3. `SETUP_TOKEN` is configured in `app/config/config.php` (never committed)
   and the caller supplies the exact same token.
4. `EXPECTED_DB_NAME` matches the configured `DB_NAME` (same safety gate the
   CLI tools use).

None of that makes leaving this directory up indefinitely a good idea —
**delete it entirely as soon as `api/DEPLOY-CPANEL-PREPROD.md`'s setup
steps are done** (the wizard's own "Selesai & Nonaktifkan Setup" button is
a courtesy safety net, not a substitute for actually deleting the folder).
Prefer the CLI (`api/bin/migrate.php`, `bin/seed.php`, `bin/create_admin.php`)
whenever SSH/Terminal is available at all — this wizard is the no-SSH
fallback, not the default path.
