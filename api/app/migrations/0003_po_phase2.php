<?php

declare(strict_types=1);

/**
 * Migration 0003: PO Phase 2 fast-track (authoritative MySQL PO module).
 *
 * Adds upload-provenance columns to po_batch (upload_type, source_filename,
 * source_hash, uploaded_by) and a new po_import immutable audit-history
 * table — see database/schema-v1-0003-po-phase2.sql's own header comment.
 * Does NOT recreate po_batch/po_item/po_store_item's original CREATE TABLE
 * statements (0001) and does NOT touch any Phase 1 master identity table.
 *
 * Same dual-location pattern as 0001/0002: the packaged copy (shipped by
 * the Phase 2 easy ZIP) is tried first, falling back to the monorepo's
 * canonical top-level copy for local/CI use.
 */
$packaged = dirname(__DIR__) . '/database/schema-v1-0003-po-phase2.sql';
$monorepo = dirname(__DIR__, 3) . '/database/schema-v1-0003-po-phase2.sql';
return is_file($packaged) ? $packaged : $monorepo;
