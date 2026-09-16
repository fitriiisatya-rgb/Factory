<?php

declare(strict_types=1);

/**
 * Migration 0001: apply the V1 draft schema.
 *
 * The canonical DDL lives in exactly one place in this monorepo —
 * /database/schema-v1.sql — so the design docs and this deploy tool can
 * never drift apart. This file is a pointer to that source, never a second
 * copy of the DDL, in either context below.
 *
 * Two possible locations, tried in order:
 *   1. api/app/database/schema-v1.sql — a copy placed here ONLY by the
 *      cPanel easy-install packaging script (dist/build-cpanel-package.sh),
 *      because the shipped ZIP does not include the whole monorepo.
 *   2. The monorepo's top-level database/schema-v1.sql — used for local
 *      development and the disposable-MariaDB test suite (api/tests/run.sh),
 *      where the full repo checkout is present.
 * Exactly one of these exists in any given deployment.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1.sql';
return is_file($packaged) ? $packaged : $monorepo;
