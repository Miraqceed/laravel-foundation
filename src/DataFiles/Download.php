<?php

namespace Miraqceed\Foundation\DataFiles;

use InvalidArgumentException;

/**
 * 1つの取り方（URL と、受け方の決まり）。作った後は変えない（with… は写しを返す）。
 *
 * - https だけ。http・相対・userinfo つき（https://user:pass@…）は作るときに InvalidArgumentException（文に URL を入れない）
 * - リダイレクトは既定で従わない。followRedirects() で、https への従いを3回まで許す
 * - 見出し（withHeaders()）の値は、失敗の文・記録に出さない
 *
 * 試しのための http の口（127.0.0.1・[::1] だけ）は private で、パッケージの試しの助けからだけ呼ぶ。
 * 設定・環境の変数・アプリの環境（app()->environment()）・容器のどれも読まない。本番で開く道はない
 */
final class Download
{
    /** リダイレクトを許したときに従う回数 */
    public const MAX_REDIRECTS = 3;

    private bool $gzip = false;

    private bool $notFoundMeansNotYet = false;

    private bool $followRedirects = false;

    /** @var array<string, string> */
    private array $headers = [];

    private function __construct(
        private readonly string $url,
        private readonly bool $loopback,
    ) {}

    public static function url(string $url): self
    {
        if (! self::isHttpsUrl($url)) {
            throw new InvalidArgumentException('データのファイルの URL は、userinfo のない https:// の URL にしてください');
        }

        return new self($url, false);
    }

    /**
     * 試しのための口：手元の HTTP の口（php -S）へ http で送る。パッケージの試しの助けが Closure::bind で呼ぶ。
     * 設定・環境・容器を読まない。通すのは 127.0.0.1・[::1] の番号つきの http だけ
     */
    private static function loopbackForTests(string $url): self
    {
        if (! self::isLoopbackUrl($url)) {
            throw new InvalidArgumentException('試しの口は、http://127.0.0.1:番号/ か http://[::1]:番号/ だけを通します');
        }

        return new self($url, true);
    }

    /** 本文を gzip として流しながら解く（解いた大きさの上限と、終わりまで解けたかを見る） */
    public function gzip(): self
    {
        $copy = clone $this;
        $copy->gzip = true;

        return $copy;
    }

    /** 404 を「まだ出ていない」として、次の取り方へ進む（月ごとに出るデータの今月の分など） */
    public function notFoundMeansNotYet(): self
    {
        $copy = clone $this;
        $copy->notFoundMeansNotYet = true;

        return $copy;
    }

    /** https への（試しの口では同じ元への）リダイレクトに、3回まで従う */
    public function followRedirects(): self
    {
        $copy = clone $this;
        $copy->followRedirects = true;

        return $copy;
    }

    /**
     * 見出しを足す（鍵の要る取り方など）。値は失敗の文・記録に出さない
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            if (! is_string($name) || preg_match('/\A[A-Za-z0-9-]{1,64}\z/', $name) !== 1 || ! is_string($value) || preg_match('/[\r\n\0]/', $value) === 1) {
                throw new InvalidArgumentException('データのファイルの見出しは、英数字と - の名前と、改行のない文字列の値にしてください');
            }
        }
        $copy = clone $this;
        $copy->headers = [...$this->headers, ...$headers];

        return $copy;
    }

    /** 送る URL（問いを含む。記録に出さない） */
    public function target(): string
    {
        return $this->url;
    }

    /** 失敗の文に書く形：問い（? から後）・# から後・userinfo を落とす */
    public function redactedUrl(): string
    {
        return self::redact($this->url);
    }

    public function isGzip(): bool
    {
        return $this->gzip;
    }

    public function treatsNotFoundAsNotYet(): bool
    {
        return $this->notFoundMeansNotYet;
    }

    public function followsRedirects(): bool
    {
        return $this->followRedirects;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * 送ってよい URL か（送る直前と、リダイレクトの先で Fetcher が確かめる）。
     * https の URL か、試しの口で作ったものなら同じ元（スキーム・ホスト・番号）の URL
     */
    public function permits(string $url): bool
    {
        if (! $this->loopback) {
            return self::isHttpsUrl($url);
        }

        return self::isLoopbackUrl($url) && self::origin($url) === self::origin($this->url);
    }

    /** 失敗の文に書く形（問い・# から後・userinfo を落とす） */
    public static function redact(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return '（読めない URL）';
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return strtolower($parts['scheme']).'://'.$parts['host'].$port.($parts['path'] ?? '');
    }

    private static function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && preg_match('/[\s\0]/', $url) !== 1;
    }

    private static function isLoopbackUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && in_array($parts['host'] ?? '', ['127.0.0.1', '[::1]'], true)
            && isset($parts['port'])
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && preg_match('/[\s\0]/', $url) !== 1;
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return strtolower(($parts['scheme'] ?? '').'://'.($parts['host'] ?? '').':'.($parts['port'] ?? ''));
    }
}
