<?php

declare(strict_types=1);

/**
 * Migration 0025: partial handover (serah terima) from production to FG —
 * production_handover + production_handover_item.
 *
 * See database/schema-v1-0025-production-handover.sql for the rationale.
 * Two new tables only.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0025-production-handover.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0025-production-handover.sql';
return is_file($packaged) ? $packaged : $monorepo;
