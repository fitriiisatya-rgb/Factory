<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Request;
use Amor\Api\Response;

final class HealthController
{
    public static function index(Request $request): void
    {
        $dbConnected = false;
        $dbVersion = null;
        try {
            $pdo = Database::pdo();
            $dbVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $dbConnected = true;
        } catch (\Throwable $e) {
            // Health check must never leak connection details — just report the boolean.
        }

        Response::json([
            'ok' => true,
            'env' => Config::get('APP_ENV'),
            'db' => $dbConnected ? 'connected' : 'disconnected',
            'dbVersion' => $dbVersion,
            'schemaVersion' => 'v1',
        ]);
    }
}
