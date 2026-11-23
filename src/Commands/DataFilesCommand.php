<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;
use Miraqceed\Foundation\DataFiles\DataFileException;
use Miraqceed\Foundation\DataFiles\DataSource;
use Miraqceed\Foundation\DataFiles\Refresher;
use Miraqceed\Foundation\DataFiles\Sources;
use Miraqceed\Foundation\DataFiles\Store;
use Miraqceed\Foundation\Failures\FailureReporter;
use RuntimeException;
use Throwable;

/**
 * 外のデータのファイル（foundation.data_files.sources の種類）を、壊さずに入れ替える。
 *
 *   php artisan foundation:data-files              # 登録したすべての種類を、1つずつ更新する
 *   php artisan foundation:data-files ip-country   # 1つだけ
 *   php artisan foundation:data-files --force      # 減りの止めだけを通す（確かめは通す。古い版・同じ版は入れない）
 *   php artisan foundation:data-files --status     # 置いたファイル・版・最後に成功した時刻（取りに行かない）
 *
 * - 種類ごとに別々に更新する（1つの失敗で、ほかを止めない）。1つでも失敗すれば終わりの値 1
 * - ほかの実行が動いていれば（手で流したものと予定のものが重なった）、そう言って成功で終わる
 * - 失敗は FailureReporter で知らせる（種類の名前と理由だけ。間引かない。schedule の出力は捨てられることが多い）
 * - schedule に入れない（入れるのはアプリ）。種類を登録していなければ、何も書かない
 */
class DataFilesCommand extends Command
{
    protected $signature = 'foundation:data-files
        {name? : 1つの種類だけを更新する}
        {--force : 減りの止めを通す（確かめは通す。古い版・同じ版は入れない）}
        {--status : 置いたファイルの様子を出す（取りに行かない）}';

    protected $description = '外のデータのファイルを、確かめてから入れ替える（foundation.data_files.sources）';

    public function handle(Sources $sources, Refresher $refresher, FailureReporter $reporter): int
    {
        try {
            $all = $sources->all();
            if (is_string($this->argument('name'))) {
                $all = [$this->argument('name') => $sources->get($this->argument('name'))];
            }
        } catch (Throwable $e) {
            // 設定の書き違い（クラスがない・同じ名前が2つ・作ると投げる）。schedule の出力は捨てられることが多いので、知らせもする。
            // Sources の文には設定の値を入れていない。ほかの例外（種類を作るときに投げた）は種類だけ
            $message = $e instanceof \LogicException ? $e->getMessage() : 'データのファイルの種類を作れません（'.$e::class.'）';
            $this->error($message);
            $reporter->report(new RuntimeException('データのファイルの設定を読めません：'.$message));

            return self::FAILURE;
        }

        if ($all === []) {
            $this->info('データのファイルの種類がありません（foundation.data_files.sources）。何もしません');

            return self::SUCCESS;
        }

        if ($this->option('status')) {
            return $this->status($all);
        }

        $failed = false;
        foreach ($all as $name => $source) {
            try {
                $this->info($refresher->refresh($source, (bool) $this->option('force'))->message);
            } catch (Throwable $e) {
                $failed = true;
                // 種類の名前と理由だけ。元の例外を previous にしない（文に URL・見出しが入りうる）
                $message = $e instanceof DataFileException ? $e->getMessage() : "{$name}：入れ替えに失敗しました（".$e::class.'）';
                $this->error($message);
                $reporter->report(new RuntimeException('データのファイルを入れ替えられません：'.$message));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param  array<string, DataSource>  $all */
    private function status(array $all): int
    {
        $rows = [];
        foreach ($all as $name => $source) {
            $store = Store::for($name);
            $version = '—';
            if ($store->hasData()) {
                try {
                    $read = $source->verify($store->dataPath());
                    $version = $read === null ? '（版なし）' : (string) $read;
                } catch (Throwable $e) {
                    $version = '読めない（'.$e::class.'）';
                }
            }
            $placed = $store->hasData() ? date('Y-m-d H:i:s', (int) filemtime($store->dataPath())) : 'なし';
            $checked = $store->checkedAt();
            $rows[] = [$name, $placed, $version, $checked === null ? 'まだ' : date('Y-m-d H:i:s', $checked)];
        }
        $this->table(['種類', '置いた時刻', '版', '最後に成功した更新'], $rows);

        return self::SUCCESS;
    }
}
