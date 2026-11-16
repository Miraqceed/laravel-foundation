<?php

namespace Miraqceed\Foundation\Ai;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * AI の費用の合計・使用量の行・知らせの印を読み書きする1か所。コンテナの singleton。
 * 例外は握らず投げる（握るのは呼ぶ側）。
 *
 * 書き込みは、呼び出しの外のトランザクションが巻き戻っても残るよう、別の接続 foundation_ai
 * （既定の接続の設定の写し）で行う。SQLite は接続が1つなので既定の接続のまま。
 */
class AiStore
{
    public const CONNECTION = 'foundation_ai';

    /** パッケージ自身の試しだけが使う内部の切り替え（テストの実行中も別の接続を使う） */
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

    private function db(): Connection
    {
        return DB::connection(self::connectionName());
    }

    /**
     * 今日・今月の費用（マイクロ円）と今日の回数。今日の行と今月の日の行（31行まで）だけを読む。
     *
     * @return array{day_cost: int, month_cost: int, day_calls: int}
     */
    public function totals(): array
    {
        $today = now()->toDateString();
        $rows = $this->db()->table('foundation_ai_totals')
            ->whereBetween('day', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
            ->get(['day', 'cost_micro_yen', 'calls']);

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
        $table = $this->db()->table('foundation_ai_totals');

        $table->insertOrIgnore(['day' => $day, 'cost_micro_yen' => 0, 'calls' => 0]);
        $this->db()->table('foundation_ai_totals')->where('day', $day)->incrementEach($increments);
    }

    /** @param array<string, mixed> $row */
    public function record(array $row): void
    {
        $this->db()->table('foundation_ai_usages')->insert($row);
    }

    /** 入れられたとき（この呼び出しが最初のとき）だけ真 */
    public function claimNotice(string $key): bool
    {
        return $this->db()->table('foundation_ai_notices')
            ->insertOrIgnore(['key' => $key, 'created_at' => now()]) === 1;
    }
}
