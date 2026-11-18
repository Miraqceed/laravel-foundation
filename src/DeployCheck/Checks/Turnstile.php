<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * Turnstile の鍵（services.turnstile.site_key・secret_key）。値は出さない。
 *
 * - どちらも空なら skip（Turnstile を使っていない）
 * - 一方だけが空なら fail（秘密がなければ確かめがすべて断る・サイトの鍵がなければウィジェットが出ない）
 * - テスト用の鍵（1x・2x・3x の後に0が並ぶ）なら fail（本番で、誰でも通すか、誰も通さない）
 */
final class Turnstile implements Check
{
    private const TEST_KEY = '/\A[123]x0{10}/';

    public function id(): string
    {
        return 'turnstile';
    }

    public function label(): string
    {
        return 'Turnstile の鍵が両方入っていて、テスト用でない（使うときだけ）';
    }

    public function run(): Result
    {
        $keys = [
            'サイトの鍵' => config('services.turnstile.site_key'),
            '秘密の鍵' => config('services.turnstile.secret_key'),
        ];
        $filled = array_filter($keys, fn ($key) => is_string($key) && $key !== '');

        if ($filled === []) {
            return Result::skip('鍵がない（Turnstile を使っていない）');
        }

        $missing = array_keys(array_diff_key($keys, $filled));
        if ($missing !== []) {
            return Result::fail(implode('・', $missing).'がない（services.turnstile）');
        }

        $test = array_keys(array_filter($filled, fn (string $key) => preg_match(self::TEST_KEY, $key) === 1));
        if ($test !== []) {
            return Result::fail(implode('・', $test).'が Cloudflare のテスト用（本番で、誰でも通すか誰も通さない）');
        }

        return Result::ok();
    }
}
