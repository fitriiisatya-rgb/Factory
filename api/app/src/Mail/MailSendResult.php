<?php

declare(strict_types=1);

namespace Amor\Api\Mail;

/**
 * Outcome of one MailTransport::send() attempt. $error, when present, is
 * ALWAYS a friendly, credential-free message safe to store in
 * shipment_email_delivery.last_error and show an Admin — never a raw
 * exception string that might echo host/port/username details, and NEVER
 * the SMTP password under any circumstance.
 */
final class MailSendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $error,
    ) {
    }

    public static function success(): self
    {
        return new self(true, null);
    }

    public static function failure(string $friendlyError): self
    {
        return new self(false, $friendlyError);
    }
}
