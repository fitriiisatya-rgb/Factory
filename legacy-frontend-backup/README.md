# Legacy Root Frontend — Rollback Backup

This folder holds a rollback copy of the **old** Amor Factory frontend
that used to sit at the site's document root (`public_html/factory/`),
kept for emergency recovery only, per the approved legacy-frontend
cutover decision.

- `amorcakes-manufacturing-v5-slate(2).html` — the single-file legacy
  frontend source this repository has carried since the very start of the
  PHP/MySQL rebuild project (it predates every phase of that rebuild and
  was never wired into any build/deploy script — see every prior
  `dist/build-cpanel-package-*.sh`'s own note that it "never touches
  `public_html/factory/index.php`, the existing frontend"). It is moved
  here, unchanged, as the best available historical record of that
  frontend's source.

**Important**: this repository has never managed the live server's actual
`public_html/factory/index.php` file directly — that file lived on the
production host outside of any package this project has ever shipped, so
it may have drifted from this snapshot over time. **Before extracting the
cutover package on the live server, the operator must back up whatever
`index.php` currently exists there** (see
`dist/README-FIRST-CPANEL-LEGACY-FRONTEND-CUTOVER.md`, step 1) — that
live-server copy, not this file, is the authoritative rollback copy for
the exact file that was actually running.

This folder is not referenced by any PHP code, build script, or the
deployed cPanel package. It exists purely as an in-repo historical/backup
record.
