<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Request;
use Amor\Api\Response;

final class DivisionController
{
    public static function index(Request $request): void
    {
        Auth::requireAuth();
        $stmt = Database::pdo()->query('SELECT division_id, name, factory_id, is_verification FROM division ORDER BY name');
        Response::json($stmt->fetchAll());
    }
}
