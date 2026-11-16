<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Database\Migrations\Migrator;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * 流していないマイグレーションがないか。既定の接続へつなぐ（読むだけ）。
 * つなげなければ例外のクラス名だけで失敗になる（Runner）
 */
final class Migrations implements Check
{
    public function id(): string
    {
        return 'migrations';
    }

    public function label(): string
    {
        return '流していないマイグレーションがない';
    }

    public function run(): Result
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return Result::fail('マイグレーションの表がない（php artisan migrate --force）');
        }

        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
        $ran = $migrator->getRepository()->getRan();
        $pending = count(array_diff(array_keys($files), $ran));

        return $pending === 0 ? Result::ok() : Result::fail("残っている: {$pending} 件（php artisan migrate --force）");
    }
}
