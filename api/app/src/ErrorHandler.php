<?php

declare(strict_types=1);

namespace Amor\Api;

final class ErrorHandler
{
    public static function handle(\Throwable $e): void
    {
        if ($e instanceof ApiException) {
            Response::error($e->status, $e->errorCode, $e->getMessage(), $e->extra);
            return;
        }

        // Never leak internals (DSN, stack trace, SQL) to the client, even in staging —
        // APP_DEBUG only affects what gets logged, not what the client sees.
        error_log(sprintf(
            '[unhandled] %s: %s in %s:%d',
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        if (Config::get('APP_DEBUG') === true) {
            error_log($e->getTraceAsString());
        }

        Response::error(500, 'INTERNAL_ERROR', 'An unexpected error occurred');
    }
}
