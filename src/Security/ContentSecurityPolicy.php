<?php

namespace Miraqceed\Foundation\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSP の値を区分（web・admin・api）ごとに作る。方針の原本は設定 foundation.security.areas で、
 * 外の元（決済・書体など）はアプリが設定で足す。nonce を足すのは、方針に NONCE の印を書いた指示だけ
 * （script-src に nonce があるとブラウザは 'unsafe-inline' を無視し、Livewire・Alpine が黙って止まる）。
 */
final class ContentSecurityPolicy
{
    /** 設定の方針に書く印。出すときに 'nonce-…' に置き換える */
    public const NONCE = "'nonce'";

    public const ENFORCED = 'Content-Security-Policy';

    public const REPORT_ONLY = 'Content-Security-Policy-Report-Only';

    /**
     * 応答に付ける CSP のヘッダー。付けないときは空。
     *
     * @return array<string, string>
     */
    public static function headers(Request $request, Response $response): array
    {
        // 開発中の例外の画面（APP_DEBUG のとき Laravel が出す）はインラインのスクリプトとスタイルで動く。
        // 制限すると原因を読めなくなる。例外から作った転送と JSON の応答は、ふだんどおり制限する
        if (self::isDebugPage($response)) {
            return [];
        }

        $policy = self::render(self::directives(self::area($request)));

        return [(config('foundation.security.report_only') ? self::REPORT_ONLY : self::ENFORCED) => $policy];
    }

    public static function area(Request $request): string
    {
        foreach ((array) config('foundation.security.area_paths', []) as $area => $patterns) {
            if ($request->is(...(array) $patterns)) {
                return (string) $area;
            }
        }

        return 'web';
    }

    /** @return array<string, list<string>> */
    public static function directives(string $area): array
    {
        $areas = (array) config('foundation.security.areas', []);
        $directives = $areas[$area] ?? $areas['web'] ?? [];

        $dev = self::devServer();
        if ($dev !== null) {
            $directives = self::withDevServer($directives, $dev);
        }

        $nonce = Nonce::get();
        foreach ($directives as $name => $sources) {
            $directives[$name] = array_map(
                fn (string $s) => $s === self::NONCE ? "'nonce-{$nonce}'" : $s,
                $sources,
            );
        }

        if (($report = self::reportPath()) !== null) {
            $directives['report-uri'] = [$report];
        }

        return $directives;
    }

    public static function reportPath(): ?string
    {
        $route = config('foundation.security.report_route');

        return is_string($route) && $route !== '' ? '/'.ltrim($route, '/') : null;
    }

    /**
     * Vite の開発サーバーを許す。開発サーバーは CSS を nonce のない <style> で足すので、
     * 開発中だけ style-src から nonce を外して 'unsafe-inline' にする（nonce があると 'unsafe-inline' は無視される）。
     *
     * @param  array<string, list<string>>  $directives
     * @return array<string, list<string>>
     */
    private static function withDevServer(array $directives, string $origin): array
    {
        $ws = preg_replace('/^http/', 'ws', $origin);

        foreach (['script-src', 'style-src', 'connect-src'] as $name) {
            $directives[$name] ??= $directives['default-src'] ?? [];
            $directives[$name][] = $origin;
        }
        $directives['connect-src'][] = $ws;

        $style = array_values(array_filter($directives['style-src'], fn ($s) => $s !== self::NONCE));
        if (count($style) !== count($directives['style-src']) && ! in_array("'unsafe-inline'", $style, true)) {
            $style[] = "'unsafe-inline'";
        }
        $directives['style-src'] = $style;

        return $directives;
    }

    private static function devServer(): ?string
    {
        if (! Vite::isRunningHot()) {
            return null;
        }

        $origin = rtrim(trim((string) file_get_contents(Vite::hotFile())), '/');

        return $origin === '' ? null : $origin;
    }

    private static function isDebugPage(Response $response): bool
    {
        return config('app.debug')
            && ($response->exception ?? null) !== null
            && $response->getStatusCode() >= 400
            && str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');
    }

    /** @param  array<string, list<string>>  $directives */
    private static function render(array $directives): string
    {
        return implode('; ', array_map(
            fn (string $name, array $sources) => trim($name.' '.implode(' ', $sources)),
            array_keys($directives),
            $directives,
        ));
    }
}
