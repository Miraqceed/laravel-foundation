<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Closure;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\DeployCheck\Status;

/**
 * 送信元のドメイン（MAIL_FROM_ADDRESS の @ より後）に、受け手が確かめる DNS の記録があるか。DNS の TXT を引くだけ。
 *
 * - mail の調べが失敗する設定（log・array・例示の送信元など）なら skip（同じことを2回言わない）
 * - SPF（TXT の v=spf1）・DMARC（_dmarc. の TXT の v=DMARC1）。foundation.deploy_check.dkim_selector があれば DKIM（<セレクター>._domainkey. の p=）
 * - 全部あれば ok。足りなければ later（公開の直前の --strict で失敗）
 * - 引けなければ info。dns_get_record() は待つ時間を決められず、応えない DNS では1回ごとに resolver の待ちだけ止まるので、
 *   1回でも引けなければ残りを引かずに終える（引くのは最大3回）。遅い・引けない環境は foundation.deploy_check.skip で外す
 * - detail にドメインの名前は出す（秘密でない）。アドレスのローカル部は出さない
 */
final class MailDomain implements Check
{
    /** @var Closure(string): (list<string>|false) 名前の TXT の記録。引けなければ false */
    private readonly Closure $txt;

    /** @param (Closure(string): (list<string>|false))|null $txt 試しが DNS を差し替える口（アプリは使わない） */
    public function __construct(?Closure $txt = null)
    {
        $this->txt = $txt ?? static function (string $name): array|false {
            if (! function_exists('dns_get_record')) {
                return false;
            }
            $records = @dns_get_record($name, DNS_TXT);
            if (! is_array($records)) {
                return false;
            }

            return array_values(array_map(
                fn (array $record): string => (string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? []))),
                $records,
            ));
        };
    }

    public function id(): string
    {
        return 'mail_domain';
    }

    public function label(): string
    {
        return '送信元のドメインに SPF・DMARC がある（DKIM はセレクターを書いたときだけ。DNS を引く）';
    }

    public function run(): Result
    {
        if ((new Mail)->run()->status === Status::Fail) {
            return Result::skip('mail の調べが失敗する設定');
        }

        $from = (string) config('mail.from.address');
        $domain = rtrim(strtolower(substr($from, (int) strrpos($from, '@') + 1)), '.');

        $missing = [];
        $checks = ['SPF' => [$domain, 'v=spf1'], 'DMARC' => ['_dmarc.'.$domain, 'v=dmarc1']];
        $selector = config('foundation.deploy_check.dkim_selector');
        $dkim = is_string($selector) && $selector !== '';
        if ($dkim) {
            $checks['DKIM'] = ["{$selector}._domainkey.{$domain}", 'p='];
        }

        foreach ($checks as $name => [$host, $needle]) {
            $records = ($this->txt)($host);
            if ($records === false) {
                // 引けなければ残りを引かない（止まるのを1回分にする）
                return Result::info("DNS を引けない（{$host}）。遅い・引けない環境では foundation.deploy_check.skip に mail_domain を足す");
            }
            if (! self::has($records, $needle)) {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            return Result::later("{$domain} に ".implode('・', $missing).' がない（迷惑メールに入る・受け取られない）');
        }

        return Result::ok($dkim ? $domain : "{$domain}（DKIM は見ていない：foundation.deploy_check.dkim_selector）");
    }

    /** @param  list<string>  $records */
    private static function has(array $records, string $needle): bool
    {
        foreach ($records as $record) {
            if (str_contains(strtolower($record), $needle)) {
                return true;
            }
        }

        return false;
    }
}
