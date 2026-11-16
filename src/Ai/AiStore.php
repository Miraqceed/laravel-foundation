<?php

namespace Miraqceed\Foundation\Ai;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * AI の費用の合計・使用量の行・知らせの印を読み書きする1か所。コンテナの singleton。
 * 例外は握らず投げる（握るのは呼ぶ側）。
 *
 * 書き込みは、呼び出しの外のトランザクションが巻き戻っても残るよう、別の接続 foundation_ai で行う。
 * アプリが database.connections.foundation_ai を定めていればそれを使い、なければ既定の接続の設定を写す。
 * SQLite とテストの実行中は既定の接続のまま。既定の接続では savepoint で包み、失敗した文が
 * アプリのトランザクションを壊さないようにする（PostgreSQL は失敗した文でトランザクションごと使えなくなる）。
 * 読み込みも書き込みの接続で行う（読み書きを分けたアプリで、遅れる複製から合計を読まない）。
 */
class AiStore
{
    public const CONNECTION = 'foundation_ai';

    /**
     * パッケージ自身の試しだけが使う内部の切り替え（テストの実行中も別の接続を使う）。
     *
     * @internal
     */
    public static bool $separateInTests = false;

    public static function connectionName(): string
    {
        $default = (string) config('database.default');

        if (config("database.connections.{$default}.driver") === 'sqlite') {
            return $default;
        }

        if (app()->runningUnitTests() && ! self::$separateInTests) {
            return $default;
        }

        if (config('database.connections.'.self::CONNECTION) === null) {
            config(['database.connections.'.self::CONNECTION => config("database.connections.{$default}")]);
        }

        return self::CONNECTION;
    }

    /**
     * 接続を渡して $work を流す。別の接続ではそのまま（自動コミット）、既定の接続では savepoint で包む。
     *
     * @template T
     *
     * @param  Closure(Connection): T  $work
     * @return T
     */
    private function run(Closure $work): mixed
    {
        $name = self::connectionName();
        $db = DB::connection($name);

        if ($name === self::CONNECTION) {
            return $work($db);
        }

        return $db->transaction(fn () => $work($db));
    }

    /**
     * 今日・今月の費用（マイクロ円）と今日の回数。今日の行と今月の日の行（31行まで）だけを読む。
     *
     * @return array{day_cost: int, month_cost: int, day_calls: int}
     */
    public function totals(): array
    {
        $today = now()->toDateString();
        $rows = $this->run(fn (Connection $db) => $db->table('foundation_ai_totals')
            ->useWritePdo()
            ->whereBetween('day', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get(['day', 'cost_micro_yen', 'calls']));

        $month = 0;
        $dayCost = 0;
        $dayCalls = 0;
        foreach ($rows as $row) {
            $month += (int) $row->cost_micro_yen;
            if (substr((string) $row->day, 0, 10) === $today) {
                $dayCost = (int) $row->cost_micro_yen;
                $dayCalls = (int) $row->calls;
            }
        }

        return ['day_cost' => $dayCost, 'month_cost' => $month, 'day_calls' => $dayCalls];
    }

    public function addCall(): void
    {
        $this->add(['calls' => 1]);
    }

    public function addCost(int $microYen): void
    {
        $this->add(['cost_micro_yen' => $microYen]);
    }

    /** その日の行を作ってから加える（読んでから書かない。DB ごとの upsert の式の違いも避ける） */
    private function add(array $increments): void
    {
        $day = now()->toDateString();

        $this->run(function (Connection $db) use ($day, $increments) {
            $db->table('foundation_ai_totals')->insertOrIgnore(['day' => $day, 'cost_micro_yen' => 0, 'calls' => 0]);
            $db->table('foundation_ai_totals')->where('day', $day)->incrementEach($increments);
        });
    }

    /** @param array<string, mixed> $row */
    public function record(array $row): void
    {
        $this->run(fn (Connection $db) => $db->table('foundation_ai_usages')->insert($row));
    }

    /** 入れられたとき（この呼び出しが最初のとき）だけ真 */
    public function claimNotice(string $key): bool
    {
        return $this->run(fn (Connection $db) => $db->table('foundation_ai_notices')
            ->insertOrIgnore(['key' => $key, 'created_at' => now()])) === 1;
    }
}
