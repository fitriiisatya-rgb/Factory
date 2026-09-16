# TEMPORARY — delete this whole directory after setup

This directory exists only for cPanel accounts with **no SSH/Terminal
access**. It must not live permanently on any reachable host.

Every script here refuses to run unless:
1. `APP_ENV` is not `production`/`live`.
2. `SETUP_TOKEN` is configured in `config/config.php` (never committed) and
   the caller supplies the exact same token.
3. `EXPECTED_DB_NAME` matches the configured `DB_NAME` (same safety gate the
   CLI tools use).

None of that makes leaving this directory up indefinitely a good idea —
**delete it as soon as `api/DEPLOY-CPANEL-PREPROD.md`'s setup steps are
done.** Prefer the CLI (`api/bin/migrate.php`, `bin/seed.php`,
`bin/create_admin.php`) whenever SSH/Terminal is available at all — this
directory is the fallback, not the default path.
