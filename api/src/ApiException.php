<?php

declare(strict_types=1);

namespace Amor\Api;

/**
 * Any controller/service throws this to produce a machine-coded error
 * response. ErrorHandler is the only place that converts it to JSON.
 */
class ApiException extends \RuntimeException
{
    // Named errorCode, not code: RuntimeException already declares a non-readonly
    // $code property (int), which a readonly promoted property can't redeclare.
    public readonly string $errorCode;

    public function __construct(
        public readonly int $status,
        string $errorCode,
        string $message,
        public readonly array $extra = []
    ) {
        parent::__construct($message);
        $this->errorCode = $errorCode;
    }
}
