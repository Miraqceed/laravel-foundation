<?php

namespace Miraqceed\Foundation\Health;

use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Event;
use Miraqceed\Foundation\Failures\FailureReporter;
use Throwable;

/**
 * 稼働の確かめの口。text/plain の `OK`（200）か `NG`（500）だけを返す。
 *
 * Laravel の既定の /up と同じく DiagnosingHealth を出し、聞き手が投げたら NG にする。
 * 既定と違い、外のスクリプト・スタイルシート・ビューを使わず、デバッグ中も原因を応答に出さない。
 * 経路は Health::route() で登録する。
 */
final class HealthCheck
{
    public function __invoke(): Response
    {
        try {
            Event::dispatch(new DiagnosingHealth);
        } catch (Throwable $e) {
            // デバッグ中も投げ直さない（既定の /up は投げ直し、エラーの画面に接続先などが出る）。原因は記録にだけ残す
            app(FailureReporter::class)->report($e);

            return self::respond('NG', 500);
        }

        return self::respond('OK', 200);
    }

    private static function respond(string $body, int $status): Response
    {
        return new Response($body, $status, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            // 間の CDN・プロキシに OK を覚えさせない
            'Cache-Control' => 'no-store',
        ]);
    }
}
