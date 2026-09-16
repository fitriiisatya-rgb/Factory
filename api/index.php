<?php

declare(strict_types=1);

require __DIR__ . '/app/autoload.php';

use Amor\Api\App;
use Amor\Api\ErrorHandler;
use Amor\Api\Request;

try {
    $request = new Request();
    App::run($request);
} catch (\Throwable $e) {
    ErrorHandler::handle($e);
}
