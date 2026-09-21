<?php

declare(strict_types=1);

namespace Amor\Api\Mail;

/**
 * A single outbound email — deliberately minimal (single HTML part, one
 * recipient). Built once per shipment notification / resend attempt by
 * ShipmentEmailService, never assembled by a transport itself.
 */
final class MailMessage
{
    public function __construct(
        public readonly string $toEmail,
        public readonly ?string $toName,
        public readonly string $fromEmail,
        public readonly string $fromName,
        public readonly string $subject,
        public readonly string $htmlBody,
    ) {
    }
}
