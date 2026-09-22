<?php

declare(strict_types=1);

/**
 * Amor Factory System — production entry point (docroot cutover).
 *
 * This repository's root corresponds to the live deployment's docroot
 * (public_html/factory/). Per the approved legacy-frontend cutover
 * decision, factory.amorgroup.id/ now serves the current Amor Factory
 * Admin UI directly instead of the old static frontend that used to live
 * at this exact path (see legacy-frontend-backup/ for the one-time
 * rollback copy kept before this cutover).
 *
 * This file is a PURE passthrough to the EXISTING, already-shipped UI
 * shell/router (api/_ui-preview/index.php) — no new routing, no new
 * business logic, no parallel auth, no legacy navigation or switch-back
 * link. Every require inside that shell (and its own bootstrap.php) uses
 * __DIR__-relative paths, so it resolves correctly no matter how this
 * file includes it — nothing under api/ needs to change, or is changed,
 * for this cutover.
 */

require __DIR__ . '/api/_ui-preview/index.php';
