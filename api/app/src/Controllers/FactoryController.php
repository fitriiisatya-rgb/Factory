<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Request;
use Amor\Api\Response;

final class FactoryController
{
    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $stmt = Database::pdo()->query('SELECT factory_id, code, name FROM factory ORDER BY name');
        Response::json($stmt->fetchAll());
    }
}
