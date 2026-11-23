<?php

namespace Miraqceed\Foundation\DataFiles;

use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Throwable;

/**
 * 1つの取り方で取り、本文を一時のファイルに流し込む。外から取ったものを信じない：
 * - 送る直前に、URL が https か（試しの口なら同じ元か）を Download で確かめ直す
 * - リダイレクトは既定で従わない。許したときも、https へ（試しの口では同じ元へ）3回まで
 * - 200 のほかは失敗（404 は呼ぶ側が「まだ出ていない」と見る）
 * - 取る大きさの上限を、受けている途中（進みの知らせ）と受けた後の両方で見る。全体の時間の上限・つなぐ時間の上限
 * - User-Agent はパッケージの名前だけ（アプリの名前・連絡先を入れない）
 * - 失敗の文に、URL の問い・userinfo・見出しの値を入れない。元の例外を previous にしない
 *
 * @internal
 */
final class Fetcher
{
    public const USER_AGENT = 'miraqceed/laravel-foundation';

    /** 全体の時間の上限（秒）。大きな DB を共用サーバーの回線で取る分 */
    public const TIMEOUT = 600;

    public const CONNECT_TIMEOUT = 10;

    public function __construct(private readonly int $timeoutSeconds = self::TIMEOUT) {}

    /**
     * @return int 200 か 404（404 は notFoundMeansNotYet() の取り方のときだけ返す）
     *
     * @throws DataFileException
     */
    public function fetch(string $name, Download $download, string $sink, int $maxBytes): int
    {
        $url = $download->target();
        $shown = $download->redactedUrl();
        // 容器で替えられても、送る直前にもう一度確かめる
        if (! $download->permits($url)) {
            throw new DataFileException("{$name}：送らない URL です（{$shown}）");
        }

        $deadline = microtime(true) + $this->timeoutSeconds;
        $guard = function (int|float $total, int|float $received) use ($name, $shown, $maxBytes, $deadline): void {
            if ((int) max($total, $received) > $maxBytes) {
                throw new DataFileException("{$name}：{$shown} が大きすぎます（取る大きさの上限 {$maxBytes} バイトを超えた）");
            }
            if (microtime(true) > $deadline) {
                throw new DataFileException("{$name}：{$shown} を取り終えられません（全体 {$this->timeoutSeconds} 秒を超えた）");
            }
        };

        try {
            $response = Http::withUserAgent(self::USER_AGENT)
                ->withHeaders($download->headers())
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout($this->timeoutSeconds)
                ->withOptions([
                    'allow_redirects' => $this->redirects($name, $download),
                    'progress' => $guard,
                    'sink' => $sink,
                    'decode_content' => false,
                ])
                ->get($url);
        } catch (Throwable $e) {
            for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof DataFileException) {
                    throw new DataFileException($cause->getMessage());
                }
            }
            throw new DataFileException("{$name}：{$shown} を取れません（".$e::class.'）');
        }

        $status = $response->status();
        if ($status === 404 && $download->treatsNotFoundAsNotYet()) {
            return 404;
        }
        if ($status !== 200) {
            $note = $response->redirect() ? '。リダイレクトには従いません' : '';
            throw new DataFileException("{$name}：{$shown} を取れません（HTTP {$status}{$note}）");
        }

        clearstatcache(true, $sink);
        $received = @filesize($sink);
        if ($received === false) {
            throw new DataFileException("{$name}：{$shown} の本文を書けません");
        }
        if ($received > $maxBytes) {
            throw new DataFileException("{$name}：{$shown} が大きすぎます（取る大きさの上限 {$maxBytes} バイトを超えた）");
        }
        if (microtime(true) > $deadline) {
            throw new DataFileException("{$name}：{$shown} を取り終えられません（全体 {$this->timeoutSeconds} 秒を超えた）");
        }

        return 200;
    }

    /** @return false|array<string, mixed> */
    private function redirects(string $name, Download $download): false|array
    {
        if (! $download->followsRedirects()) {
            return false;
        }

        return [
            'max' => Download::MAX_REDIRECTS,
            'strict' => true,
            'referer' => false,
            'protocols' => ['https', 'http'],
            'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) use ($name, $download): void {
                if (! $download->permits((string) $uri)) {
                    throw new DataFileException("{$name}：許さない先へのリダイレクトです（".Download::redact((string) $uri).'）');
                }
            },
        ];
    }
}
