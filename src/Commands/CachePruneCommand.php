<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * database のキャッシュの期限切れの行を消す。
 *
 * Laravel の database のストアは、期限切れの行を、その鍵がもう一度読まれたときにだけ消す。
 * 二度と来ない接続元の回数の制限の行などが表に残り続け、容量の決まった DB を食う。
 * schedule に入れるのはアプリ（入れただけでは何も消えない）。ロックの表は Laravel がくじで消すので触らない。
 */
class CachePruneCommand extends Command
{
    // 大きな表を1回の DELETE で消さない（PostgreSQL に DELETE ... LIMIT がないので、鍵を読んでから消す）
    private const BATCH = 1000;

    // 1回の実行の長さ。毎分の schedule でも次の回と重ならない
    private const MAX_SECONDS = 60;

    protected $signature = 'foundation:cache-prune {--store= : キャッシュのストアの名前（既定は cache.default）}';

    protected $description = 'database のキャッシュの期限切れの行を消す';

    public function handle(): int
    {
        $name = $this->option('store') ?: config('cache.default');
        $config = config("cache.stores.{$name}");
        if (($config['driver'] ?? null) !== 'database') {
            $this->info("キャッシュのストア {$name} は database でないので、何もしません");

            return self::SUCCESS;
        }

        $connection = Cache::store($name)->getStore()->getConnection();
        $table = $config['table'] ?? 'cache';
        $deadline = hrtime(true) + self::MAX_SECONDS * 1_000_000_000;
        $deleted = 0;

        do {
            // 期限はストアと同じく今の時刻（秒）で見る
            $now = Carbon::now()->getTimestamp();
            $keys = $connection->table($table)
                ->where('expiration', '<=', $now)
                ->orderBy('expiration')
                ->limit(self::BATCH)
                ->pluck('key')
                ->all();
            if ($keys === []) {
                break;
            }

            // 読んだ後にアプリが書き直した行（期限が延びた行）は消さない
            $count = $connection->table($table)
                ->whereIn('key', $keys)
                ->where('expiration', '<=', $now)
                ->delete();
            $deleted += $count;
            // 1件も消せなかった回で止める（同じ鍵を読み続けないため）。読んだ1000件がすべて書き直されていたときは、
            // 期限切れの行が残っていても止まるが、めったに起きず、次の回で消える
        } while (count($keys) === self::BATCH && $count > 0 && hrtime(true) < $deadline);

        $this->info("期限切れのキャッシュの行を {$deleted} 行消しました（{$table}）");

        return self::SUCCESS;
    }
}
