<?php

declare(strict_types=1);

namespace Amor\Api\Mail;

/**
 * Swappable send mechanism. Production uses SmtpMailTransport; the
 * automated test suite uses FakeMailTransport (task's own explicit "do
 * not send real email from automated tests by default") — see
 * MailTransportFactory for how the choice is made.
 */
interface MailTransport
{
    public function send(MailMessage $message): MailSendResult;
}
