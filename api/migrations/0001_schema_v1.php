<?php

declare(strict_types=1);

/**
 * Migration 0001: apply the V1 draft schema.
 *
 * The canonical DDL lives in exactly one place — /database/schema-v1.sql —
 * so the design docs and this deploy tool can never drift apart. This file
 * is a pointer to that source, not a second copy of the DDL.
 */
return dirname(__DIR__, 2) . '/database/schema-v1.sql';
