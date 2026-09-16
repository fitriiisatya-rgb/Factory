<?php

declare(strict_types=1);

namespace Amor\Api\Controllers;

use Amor\Api\ApiException;
use Amor\Api\Auth;
use Amor\Api\Request;
use Amor\Api\Response;

final class AuthController
{
    public static function login(Request $request): void
    {
        $username = (string) $request->input('username', '');
        $password = (string) $request->input('password', '');
        if ($username === '' || $password === '') {
            throw new ApiException(400, 'MISSING_CREDENTIALS', 'username and password are required');
        }

        $user = Auth::attemptLogin($username, $password);

        Response::json([
            'user' => $user,
            'csrfToken' => $_SESSION['csrf_token'],
        ]);
    }

    public static function logout(Request $request): void
    {
        Auth::logout();
        Response::noContent();
    }

    public static function me(Request $request): void
    {
        Response::json(Auth::me());
    }
}
