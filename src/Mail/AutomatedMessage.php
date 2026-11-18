<?php

namespace Miraqceed\Foundation\Mail;

use InvalidArgumentException;
use Symfony\Component\Mime\Email;

/**
 * 自動のメールの見出しを付ける。通知・Mailable の withSymfonyMessage の中で呼ぶ：
 *
 *   ->withSymfonyMessage(fn (Email $email) => AutomatedMessage::mark($email, 'notification.'.$this->notification->id));
 *
 * - Auto-Submitted: auto-generated（RFC 3834）。休暇の自動返信などが返事をしない。既にあれば変えない
 * - $id を渡せば Message-ID を <$id@APP_URL のホスト> にする。同じ通知を送り直しても同じ Message-ID になり、
 *   受け手は同じメールと分かる（重ねて見せないかは受け手しだい）。要求の Host は使わない
 */
final class AutomatedMessage
{
    /** Message-ID の左側に使う id の形（見出しの書き換え・改行を許さない） */
    private const ID_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,127}\z/';

    /**
     * @throws InvalidArgumentException id の形が違う・APP_URL のホストが取れないとき（文に id の値は入れない）
     */
    public static function mark(Email $email, ?string $id = null): Email
    {
        $headers = $email->getHeaders();

        if (! $headers->has('Auto-Submitted')) {
            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
        }

        if ($id !== null) {
            if (preg_match(self::ID_PATTERN, $id) !== 1) {
                throw new InvalidArgumentException('Message-ID の id は、英数字と . - _ だけの128字までにしてください');
            }
            $headers->remove('Message-ID');
            $headers->addIdHeader('Message-ID', $id.'@'.self::host());
        }

        return $email;
    }

    /** APP_URL のホスト（小文字。日本語のドメインは xn-- の形） */
    private static function host(): string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new InvalidArgumentException('APP_URL からホストが取れないので、Message-ID を作れません');
        }

        if (preg_match('/[^\x00-\x7F]/', $host) === 1) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw new InvalidArgumentException('APP_URL のホストを、メールの見出しに書ける形にできません');
            }
            $host = $ascii;
        }

        return strtolower($host);
    }
}
