<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Support\DatabaseTimezone;

/**
 * DB の接続の時間帯が、アプリの時間帯とずれていないかを確かめる。ずれていれば終わりの値 1（配置の台本・CI で使う）。
 * つながらない・オプションが空のときは 2（ずれと見分ける）。自動では流れない
 */
class DbTimezoneCommand extends Command
{
    /** つながらない・オプションが空のときの終わりの値 */
    public const UNCHECKED = 2;

    protected $signature = 'foundation:db-timezone {--connection= : 確かめる接続（既定は database.default）}';

    protected $description = 'DB の接続の時間帯がアプリの時間帯とずれていないかを確かめる（ずれていれば 1、確かめられなければ 2）';

    public function handle(): int
    {
        $connection = $this->option('connection');
        // 値の無い指定（--connection=）を既定の接続と読まない
        if ($connection !== null && (! is_string($connection) || $connection === '')) {
            $this->error('--connection に接続の名前を書いてください（既定の接続なら、--connection を付けない）');

            return self::UNCHECKED;
        }
        $name = $connection ?? (string) config('database.default');

        try {
            if (DatabaseTimezone::databaseOffset($connection) === null) {
                $this->line("接続 {$name}（".DB::connection($connection)->getDriverName().'）は見ません（PostgreSQL・MySQL だけを見ます）');

                return self::SUCCESS;
            }

            $mismatch = DatabaseTimezone::mismatch($connection);
        } catch (QueryException|\PDOException $e) {
            // 例外の文はホスト・ポート・DB の名前を含むので出さない
            $this->error("接続 {$name} につながらず、時間帯を確かめられませんでした（".$e::class.'）');

            return self::UNCHECKED;
        }

        if ($mismatch !== null) {
            $this->error($mismatch);

            return self::FAILURE;
        }

        $this->line("接続 {$name} の時間帯は、アプリの時間帯とそろっています");

        return self::SUCCESS;
    }
}
