<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Database;
use Amor\Api\Idempotency;
use Amor\Api\Request;
use Amor\Api\Response;
use Amor\Api\Users\UserService;
use PDO;

/**
 * ADMIN-ONLY user/driver account management. Every mutating action here
 * requires the ADMIN role (never just "any authenticated user") — this is
 * the one part of the app that can create logins and grant roles, so it is
 * intentionally the most tightly gated controller in the codebase.
 */
final class UserController
{
    public static function index(Request $request): void
    {
        Auth::requireRole('ADMIN');
        $service = new UserService(Database::pdo());
        $q = $request->query('q');
        $activeParam = $request->query('active');
        $active = $activeParam === null ? null : in_array($activeParam, ['1', 'true'], true);
        Response::json([
            'users' => $service->listUsers($q, $active),
            'roles' => $service->listRoles(),
        ]);
    }

    public static function create(Request $request): void
    {
        $actorId = Auth::requireRole('ADMIN');
        $username = (string) $request->input('username', '');
        $fullName = (string) $request->input('fullName', '');
        $password = (string) $request->input('password', '');
        $passwordConfirm = (string) $request->input('passwordConfirm', '');
        $roles = $request->input('roles', []);
        if (!is_array($roles)) {
            throw new ApiException(400, 'INVALID_ROLES', 'roles must be an array');
        }

        Idempotency::handle($request, 'POST /api/users', function (PDO $pdo) use ($request, $actorId, $username, $fullName, $password, $passwordConfirm, $roles) {
            $service = new UserService($pdo);
            $dto = $service->createUser($username, $fullName, $password, $passwordConfirm, $roles, $actorId, $request->header('Idempotency-Key'));
            return [
                'status' => 201,
                'envelope' => ['ok' => true, 'data' => $dto],
                'recordType' => 'user',
                'recordKey' => (string) $dto['userId'],
            ];
        });
    }

    public static function update(Request $request): void
    {
        $actorId = Auth::requireRole('ADMIN');
        $id = (int) $request->routeParams['id'];
        $fullName = (string) $request->input('fullName', '');

        Idempotency::handle($request, 'PUT /api/users/{id}', function (PDO $pdo) use ($request, $actorId, $id, $fullName) {
            $service = new UserService($pdo);
            $dto = $service->updateProfile($id, $fullName, $actorId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'user', 'recordKey' => (string) $id];
        });
    }

    public static function updateRoles(Request $request): void
    {
        $actorId = Auth::requireRole('ADMIN');
        $id = (int) $request->routeParams['id'];
        $roles = $request->input('roles', []);
        if (!is_array($roles)) {
            throw new ApiException(400, 'INVALID_ROLES', 'roles must be an array');
        }

        Idempotency::handle($request, 'PUT /api/users/{id}/roles', function (PDO $pdo) use ($request, $actorId, $id, $roles) {
            $service = new UserService($pdo);
            $dto = $service->updateRoles($id, $roles, $actorId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'user', 'recordKey' => (string) $id];
        });
    }

    public static function resetPassword(Request $request): void
    {
        $actorId = Auth::requireRole('ADMIN');
        $id = (int) $request->routeParams['id'];
        $password = (string) $request->input('password', '');
        $passwordConfirm = (string) $request->input('passwordConfirm', '');

        Idempotency::handle($request, 'POST /api/users/{id}/reset-password', function (PDO $pdo) use ($request, $actorId, $id, $password, $passwordConfirm) {
            $service = new UserService($pdo);
            $dto = $service->resetPassword($id, $password, $passwordConfirm, $actorId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'user', 'recordKey' => (string) $id];
        });
    }

    public static function activate(Request $request): void
    {
        self::setActive($request, true);
    }

    public static function deactivate(Request $request): void
    {
        self::setActive($request, false);
    }

    private static function setActive(Request $request, bool $active): void
    {
        $actorId = Auth::requireRole('ADMIN');
        $id = (int) $request->routeParams['id'];
        $endpoint = $active ? 'POST /api/users/{id}/activate' : 'POST /api/users/{id}/deactivate';

        Idempotency::handle($request, $endpoint, function (PDO $pdo) use ($request, $actorId, $id, $active) {
            $service = new UserService($pdo);
            $dto = $service->setActive($id, $active, $actorId, $request->header('Idempotency-Key'));
            return ['status' => 200, 'envelope' => ['ok' => true, 'data' => $dto], 'recordType' => 'user', 'recordKey' => (string) $id];
        });
    }
}
