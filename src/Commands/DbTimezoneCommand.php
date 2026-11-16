<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Support\DatabaseTimezone;

/**
 * DB の接続の時間帯が、アプリの時間帯とずれていないかを確かめる。ずれていれば終わりの値 1（配置の台本・CI で使う）。
 * 自動では流れない
 */
class DbTimezoneCommand extends Command
{
    protected $signature = 'foundation:db-timezone {--connection= : 確かめる接続（既定は database.default）}';

    protected $description = 'DB の接続の時間帯がアプリの時間帯とずれていないかを確かめる（ずれていれば 1）';

    public function handle(): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        $name = $connection ?? (string) config('database.default');

        if (DatabaseTimezone::databaseOffset($connection) === null) {
            $this->line("接続 {$name}（".DB::connection($connection)->getDriverName().'）は見ません（PostgreSQL・MySQL だけを見ます）');

            return self::SUCCESS;
        }

        $mismatch = DatabaseTimezone::mismatch($connection);
        if ($mismatch !== null) {
            $this->error($mismatch);

            return self::FAILURE;
        }

        $this->line("接続 {$name} の時間帯は、アプリの時間帯とそろっています");

        return self::SUCCESS;
    }
}
