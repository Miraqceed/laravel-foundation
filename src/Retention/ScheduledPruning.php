<?php

namespace Miraqceed\Foundation\Retention;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Miraqceed\Foundation\Monitoring\WatchList;
use ReflectionClass;

/**
 * 台帳の期限の消し方（prunedBy のモデル・prunedByCommand のコマンド）が schedule にあるかを確かめる。
 * DataMapAssertions::assertScheduled() と deploy-check の retention_schedule が使う
 *
 * @internal
 */
final class ScheduledPruning
{
    /**
     * schedule にない消し方と、足す1行
     *
     * @param  bool  $lookAtDatabase  パッケージの表を、表があるかで数える（試し）。偽なら部品の設定だけで数える（本番）
     * @param  bool  $currentEnvironmentOnly  今の環境で動く処理だけを数える（本番）
     * @return list<string>
     */
    public static function missing(ResolvedMap $map, bool $lookAtDatabase, bool $currentEnvironmentOnly): array
    {
        $commands = self::scheduledCommands($currentEnvironmentOnly);

        $missingModels = [];
        $missingCommands = [];
        foreach ($map->tables($lookAtDatabase) as $name => $rule) {
            foreach ($rule->models() as $model) {
                if (! self::prunesModel($commands, $model)) {
                    $missingModels[$model][] = $name;
                }
            }
            foreach ($rule->commands() as $command) {
                if (! self::runsCommand($commands, $command)) {
                    $missingCommands[$command][] = $name;
                }
            }
        }

        $problems = [];
        if ($missingModels !== []) {
            $problems[] = sprintf(
                "model:prune が schedule にないモデル：%s\n  足す1行：Schedule::command('model:prune', ['--model' => [%s]])->daily();",
                implode('・', array_map(fn ($model, $tables) => $model.'（'.implode('・', $tables).'）', array_keys($missingModels), $missingModels)),
                implode(', ', array_map(fn ($model) => '\\'.ltrim($model, '\\').'::class', array_keys($missingModels))),
            );
        }
        foreach ($missingCommands as $command => $tables) {
            $problems[] = sprintf(
                "schedule にないコマンド：%s（%s）\n  足す1行：Schedule::command('%s')->daily();",
                $command,
                implode('・', $tables),
                $command,
            );
        }

        return $problems;
    }

    /** @return list<string> schedule のコマンドの文字列 */
    private static function scheduledCommands(bool $currentEnvironmentOnly): array
    {
        app(WatchList::class)->loadConsoleSchedule();

        $environment = app()->environment();
        $commands = [];
        foreach (app(Schedule::class)->events() as $event) {
            /** @var Event $event */
            if (! is_string($event->command) || ($currentEnvironmentOnly && ! $event->runsInEnvironment($environment))) {
                continue;
            }
            $commands[] = $event->command;
        }

        return $commands;
    }

    /** @param  list<string>  $commands */
    private static function runsCommand(array $commands, string $name): bool
    {
        foreach ($commands as $command) {
            if (self::hasToken($command, $name)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $commands */
    private static function prunesModel(array $commands, string $model): bool
    {
        $model = ltrim($model, '\\');

        foreach ($commands as $command) {
            if (! self::hasToken($command, 'model:prune')) {
                continue;
            }

            $given = self::options($command, 'model');
            if ($given !== []) {
                if (in_array($model, array_map(fn ($class) => ltrim($class, '\\'), $given), true)) {
                    return true;
                }

                continue;
            }

            // --model がなければ、--path（既定 app/Models）の中のモデルから --except を除いたものを消す
            if (in_array($model, array_map(fn ($class) => ltrim($class, '\\'), self::options($command, 'except')), true)) {
                continue;
            }
            $paths = self::options($command, 'path');
            $paths = $paths === [] ? [app_path('Models')] : array_map(fn ($path) => base_path($path), $paths);
            if (self::modelIsIn($model, $paths)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $paths */
    private static function modelIsIn(string $model, array $paths): bool
    {
        if (! class_exists($model)) {
            return false;
        }

        $file = realpath((string) (new ReflectionClass($model))->getFileName());
        foreach ($paths as $path) {
            $dir = realpath($path);
            if ($file !== false && $dir !== false && str_starts_with($file, $dir.DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    /** コマンドの文字列に、その語がそのままある（引用符の中を含む） */
    private static function hasToken(string $command, string $token): bool
    {
        return preg_match('/(?:^|[\s\'"])'.preg_quote($token, '/').'(?=$|[\s\'"])/', $command) === 1;
    }

    /**
     * --name=値・--name 値 の値（引用符を外す）
     *
     * @return list<string>
     */
    private static function options(string $command, string $name): array
    {
        preg_match_all('/--'.preg_quote($name, '/').'(?:=|\s+)(?:\'([^\']*)\'|"([^"]*)"|([^\s\'"]+))/', $command, $matches, PREG_SET_ORDER);

        // 当たった1つの組だけが空でない（当たらなかった後ろの組は配列にない）
        return array_map(fn (array $match) => ($match[1] ?? '').($match[2] ?? '').($match[3] ?? ''), $matches);
    }
}
