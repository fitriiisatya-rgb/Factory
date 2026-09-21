<?php

declare(strict_types=1);

namespace Amor\Api;

use Amor\Api\Controllers\Admin\MigrationController;
use Amor\Api\Controllers\AuthController;
use Amor\Api\Controllers\DashboardController;
use Amor\Api\Controllers\DispatchController;
use Amor\Api\Controllers\DivisionController;
use Amor\Api\Controllers\DoController;
use Amor\Api\Controllers\FactoryController;
use Amor\Api\Controllers\FgController;
use Amor\Api\Controllers\HealthController;
use Amor\Api\Controllers\PoController;
use Amor\Api\Controllers\ProductController;
use Amor\Api\Controllers\ProductionController;
use Amor\Api\Controllers\ReceiptController;
use Amor\Api\Controllers\StoreController;
use Amor\Api\Controllers\UserController;

/**
 * Wires routes + cross-cutting guards. Shared by the real front controller
 * (public/index.php) and the test harness, so both exercise identical
 * routing/middleware behavior.
 */
final class App
{
    private const CSRF_EXEMPT = [
        'POST /api/auth/login', // no session exists yet to hold a CSRF token
    ];

    public static function run(Request $request): void
    {
        date_default_timezone_set((string) Config::get('APP_TIMEZONE', 'UTC'));
        Auth::bootSession();

        $router = new Router();

        $router->get('/api/health', [HealthController::class, 'index']);

        $router->post('/api/auth/login', [AuthController::class, 'login']);
        $router->post('/api/auth/logout', [AuthController::class, 'logout']);
        $router->get('/api/auth/me', [AuthController::class, 'me']);

        $router->get('/api/factories', [FactoryController::class, 'index']);
        $router->get('/api/divisions', [DivisionController::class, 'index']);

        $router->get('/api/products', [ProductController::class, 'index']);
        $router->get('/api/products/{id}', [ProductController::class, 'show']);
        $router->post('/api/products', [ProductController::class, 'create']);
        $router->put('/api/products/{id}', [ProductController::class, 'update']);
        $router->post('/api/products/{id}/aliases', [ProductController::class, 'addAlias']);

        $router->get('/api/stores', [StoreController::class, 'index']);
        $router->get('/api/stores/{id}', [StoreController::class, 'show']);
        $router->post('/api/stores', [StoreController::class, 'create']);
        $router->put('/api/stores/{id}', [StoreController::class, 'update']);
        $router->post('/api/stores/{id}/aliases', [StoreController::class, 'addAlias']);

        $router->get('/api/po', [PoController::class, 'index']);
        $router->get('/api/po/current', [PoController::class, 'current']);
        $router->get('/api/po/history', [PoController::class, 'history']);
        $router->post('/api/po/preview', [PoController::class, 'preview']);
        $router->post('/api/po/import', [PoController::class, 'import']);
        $router->get('/api/po/{batchId}', [PoController::class, 'show']);

        $router->get('/api/production/target', [ProductionController::class, 'target']);
        $router->get('/api/production/history', [ProductionController::class, 'history']);
        $router->get('/api/production', [ProductionController::class, 'index']);
        $router->post('/api/production', [ProductionController::class, 'create']);
        $router->get('/api/production/{id}', [ProductionController::class, 'show']);
        $router->patch('/api/production/{id}', [ProductionController::class, 'update']);
        $router->post('/api/production/{id}/submit', [ProductionController::class, 'submit']);
        $router->post('/api/production/{id}/reopen', [ProductionController::class, 'reopen']);

        $router->get('/api/fg/target', [FgController::class, 'target']);
        $router->get('/api/fg/availability', [FgController::class, 'availability']);
        $router->get('/api/fg/history', [FgController::class, 'history']);
        $router->get('/api/fg', [FgController::class, 'index']);
        $router->post('/api/fg', [FgController::class, 'create']);
        $router->get('/api/fg/{id}', [FgController::class, 'show']);
        $router->patch('/api/fg/{id}', [FgController::class, 'update']);
        $router->post('/api/fg/{id}/refresh-source', [FgController::class, 'refreshSource']);
        $router->post('/api/fg/{id}/submit', [FgController::class, 'submit']);
        $router->post('/api/fg/{id}/reopen', [FgController::class, 'reopen']);

        $router->get('/api/do/preview', [DoController::class, 'preview']);
        $router->get('/api/do/stores', [DoController::class, 'stores']);
        $router->get('/api/do/history', [DoController::class, 'history']);
        $router->get('/api/do', [DoController::class, 'index']);
        $router->post('/api/do', [DoController::class, 'create']);
        $router->post('/api/do/generate-bulk', [DoController::class, 'generateBulk']);
        $router->get('/api/do/{id}', [DoController::class, 'show']);
        $router->post('/api/do/{id}/preprint', [DoController::class, 'preprint']);
        $router->post('/api/do/{id}/refresh-po', [DoController::class, 'refreshPo']);
        $router->post('/api/do/{id}/cancel', [DoController::class, 'cancel']);
        $router->get('/api/do/{id}/shipments', [DoController::class, 'shipments']);
        $router->post('/api/do/{id}/shipment-preview', [DoController::class, 'shipmentPreview']);
        $router->post('/api/do/{id}/ship', [DoController::class, 'ship']);

        $router->get('/api/dashboard/summary', [DashboardController::class, 'summary']);

        $router->get('/api/admin/migration/products', [MigrationController::class, 'indexProducts']);
        $router->post('/api/admin/migration/products/{id}/resolve', [MigrationController::class, 'resolveProduct']);
        $router->post('/api/admin/migration/products/{id}/flag-conflict', [MigrationController::class, 'flagConflictProduct']);
        $router->get('/api/admin/migration/stores', [MigrationController::class, 'indexStores']);
        $router->post('/api/admin/migration/stores/{id}/resolve', [MigrationController::class, 'resolveStore']);
        $router->post('/api/admin/migration/stores/{id}/flag-conflict', [MigrationController::class, 'flagConflictStore']);

        // Phase 5.5 — Driver portal (session auth + role DRIVER/ADMIN, CSRF required like every other mutating route).
        $router->get('/api/dispatch/available', [DispatchController::class, 'available']);
        $router->post('/api/dispatch/claim', [DispatchController::class, 'claim']);
        $router->post('/api/dispatch/claims/{id}/release', [DispatchController::class, 'release']);
        $router->get('/api/dispatch/mine', [DispatchController::class, 'mine']);
        $router->get('/api/dispatch/route', [DispatchController::class, 'route']);
        $router->post('/api/dispatch/route/reorder', [DispatchController::class, 'reorderRoute']);
        $router->get('/api/dispatch/route/stops/{storeId}', [DispatchController::class, 'stopDetail']);
        $router->get('/api/dispatch/route/stops/{storeId}/shipments', [DispatchController::class, 'stopShipments']);
        $router->post('/api/dispatch/departures', [DispatchController::class, 'departures']);
        $router->get('/api/dispatch/history', [DispatchController::class, 'history']);
        $router->get('/api/dispatch/shipments/{id}', [DispatchController::class, 'shipmentDetail']);

        // Phase 5.5 — Store Receipt portal. PUBLIC (no session): the
        // high-entropy {token} path segment IS the access control, never a
        // raw delivery_order_id — see ReceiptController's own docblock.
        $router->get('/api/receive/{token}', [ReceiptController::class, 'publicView']);
        $router->post('/api/receive/{token}/shipments/{shipmentId}/confirm', [ReceiptController::class, 'confirm']);

        // Phase 5.5 — Admin discrepancy verification (ADMIN-only, normal session/CSRF).
        $router->get('/api/admin/receipts', [ReceiptController::class, 'adminList']);
        $router->post('/api/admin/receipts/{id}/verify', [ReceiptController::class, 'adminVerify']);
        $router->post('/api/admin/receipts/{id}/evidence', [ReceiptController::class, 'adminUploadEvidence']);
        $router->get('/api/admin/receipts/evidence/{id}', [ReceiptController::class, 'adminEvidence']);

        // User / Driver Account Management (ADMIN-only, normal session/CSRF/Idempotency-Key —
        // same guards as every other mutating route, nothing special-cased).
        $router->get('/api/users', [UserController::class, 'index']);
        $router->post('/api/users', [UserController::class, 'create']);
        $router->put('/api/users/{id}', [UserController::class, 'update']);
        $router->put('/api/users/{id}/roles', [UserController::class, 'updateRoles']);
        $router->post('/api/users/{id}/reset-password', [UserController::class, 'resetPassword']);
        $router->post('/api/users/{id}/activate', [UserController::class, 'activate']);
        $router->post('/api/users/{id}/deactivate', [UserController::class, 'deactivate']);

        $routeKey = $request->method . ' ' . $request->path;
        // The public receipt-confirm route has no session, so it has no CSRF
        // token to check — same "no session exists yet" reasoning as the
        // login exemption above, scoped by prefix since {token}/{shipmentId}
        // are dynamic segments a literal CSRF_EXEMPT string can't match.
        $isPublicReceiptConfirm = $request->method === 'POST' && str_starts_with($request->path, '/api/receive/');
        if ($request->method !== 'GET' && !in_array($routeKey, self::CSRF_EXEMPT, true) && !$isPublicReceiptConfirm) {
            // CSRF is checked centrally, before the handler runs, per
            // docs/php-api-contract-v1.md §1 rule 3 — no handler can forget it.
            Csrf::verify($request);
        }

        $router->dispatch($request);
    }
}
