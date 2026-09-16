<?php

declare(strict_types=1);

namespace Amor\Api;

use Amor\Api\Controllers\AuthController;
use Amor\Api\Controllers\DivisionController;
use Amor\Api\Controllers\FactoryController;
use Amor\Api\Controllers\HealthController;
use Amor\Api\Controllers\ProductController;
use Amor\Api\Controllers\StoreController;

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
        $router->post('/api/products', [ProductController::class, 'create']);
        $router->put('/api/products/{id}', [ProductController::class, 'update']);
        $router->post('/api/products/{id}/aliases', [ProductController::class, 'addAlias']);

        $router->get('/api/stores', [StoreController::class, 'index']);
        $router->post('/api/stores', [StoreController::class, 'create']);
        $router->put('/api/stores/{id}', [StoreController::class, 'update']);
        $router->post('/api/stores/{id}/aliases', [StoreController::class, 'addAlias']);

        $routeKey = $request->method . ' ' . $request->path;
        if ($request->method !== 'GET' && !in_array($routeKey, self::CSRF_EXEMPT, true)) {
            // CSRF is checked centrally, before the handler runs, per
            // docs/php-api-contract-v1.md §1 rule 3 — no handler can forget it.
            Csrf::verify($request);
        }

        $router->dispatch($request);
    }
}
