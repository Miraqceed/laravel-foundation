<?php

namespace Miraqceed\Foundation\Failures;

use Throwable;

/**
 * 投げない report()。
 *
 * Laravel の report() は、ログのファイルを開けない・ロガーを作れないときに投げる（ロガーを作れないときは、元の例外を投げ直す）。
 * 失敗を記録してから続けるつもりの catch の中で投げると、本筋（転送・バッチ・終了コード）ごと止まる。
 * ここでは記録の失敗を捨て、PHP の error_log() に1行だけ残す（ログのチャネルを通らないので、Monolog が壊れていても残る）。
 *
 * 例外は書き換えない（元のまま report() に渡す）。アプリの dontReport・reportable・level・context はそのまま効く。
 * 文に入った IP などは伏せないので、要るときは redactIp() や Redactor で文を作ってから渡す。
 */
final class FailureReporter
{
    public function __construct(private readonly ReportThrottle $throttle = new ReportThrottle) {}

    /**
     * 鍵を渡すと、その鍵ごとに窓（秒）の中で1回だけ記録する（プロセスをまたぐ）。鍵は失敗の種類にし、利用者ごとの値を入れない。
     * どの道でも投げない。
     */
    public function report(Throwable $e, ?string $throttleKey = null, int $windowSeconds = 60): void
    {
        try {
            if ($throttleKey !== null && ! $this->throttle->shouldReport($throttleKey, $windowSeconds)) {
                return;
            }

            report($e);
        } catch (Throwable $failure) {
            self::noteFailure($e, $failure);
        }
    }

    /**
     * 文の中の、その IP の書き方のすべてを [ip] にする。IPv6 の大文字・省略・全部の形、IPv4 を埋め込んだ IPv6 の中の IPv4 も。
     * IPv4 は長い数の一部では当てない（203.0.113.5 を伏せても 203.0.113.50 は残る）。投げない。
     */
    public static function redactIp(string $message, ?string $ip): string
    {
        return IpSpellings::redact($message, $ip, '[ip]');
    }

    /** 記録に失敗したことを1行だけ残す。文には IP などが入りうるので、クラスの名前だけ */
    private static function noteFailure(Throwable $e, Throwable $failure): void
    {
        try {
            error_log(sprintf('foundation: report() failed for %s (%s)', $e::class, $failure::class));
        } catch (Throwable) {
            // 残せなくても、本筋は止めない
        }
    }
}
