<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\Mail\ReservedEmailDomains;

/**
 * config/mail.php の既定値（log・127.0.0.1・hello@example.com）のままでは、メールはどこにも届かない
 */
final class Mail implements Check
{
    private const LOCAL_HOSTS = ['', '127.0.0.1', 'localhost', '::1'];

    public function id(): string
    {
        return 'mail';
    }

    public function label(): string
    {
        return 'メールが届く設定（送り方が log・array でない。送り元が実在するメールアドレス）';
    }

    public function run(): Result
    {
        $mailer = config('mail.default');
        $transport = is_string($mailer) ? config("mail.mailers.{$mailer}.transport") : null;

        if (! is_string($transport) || in_array($transport, ['log', 'array'], true)) {
            return Result::fail('送り方が log・array か、決まっていない');
        }
        if ($transport === 'smtp' && in_array((string) config("mail.mailers.{$mailer}.host"), self::LOCAL_HOSTS, true)) {
            return Result::fail('SMTP のホストが空か手元（127.0.0.1・localhost）');
        }

        $from = config('mail.from.address');
        if (! is_string($from) || filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            return Result::fail('MAIL_FROM_ADDRESS がメールアドレスでない');
        }
        if (ReservedEmailDomains::isReserved($from)) {
            return Result::fail('MAIL_FROM_ADDRESS が実在しないドメイン（example.com など）');
        }

        return Result::ok();
    }
}
