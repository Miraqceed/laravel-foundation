<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * Vite を使うアプリ（vite.config.* がある）で、組み立てた manifest があるか。
 * 限界：manifest の場所を変えたアプリ（Vite::useBuildDirectory()・useManifestFilename()）は見ない
 */
final class ViteManifest implements Check
{
    private const CONFIGS = ['vite.config.js', 'vite.config.ts', 'vite.config.mjs', 'vite.config.mts', 'vite.config.cjs', 'vite.config.cts'];

    private const MAX_BYTES = 1_048_576;

    public function id(): string
    {
        return 'vite_manifest';
    }

    public function label(): string
    {
        return '画面の組み立てが済んでいる（public/build/manifest.json。npm run build）';
    }

    public function run(): Result
    {
        $usesVite = array_filter(self::CONFIGS, fn (string $name) => is_file(base_path($name))) !== [];
        if (! $usesVite) {
            return Result::skip('vite.config.* がない');
        }

        $manifest = public_path('build/manifest.json');
        if (! is_file($manifest) || ! is_readable($manifest)) {
            return Result::fail('ない');
        }
        if ((int) filesize($manifest) > self::MAX_BYTES) {
            return Result::fail('大きすぎる');
        }

        $decoded = json_decode((string) file_get_contents($manifest), true);

        return is_array($decoded) && $decoded !== [] ? Result::ok() : Result::fail('JSON として読めない');
    }
}
