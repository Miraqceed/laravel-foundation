<?php

namespace Miraqceed\Foundation\Login;

use Illuminate\Support\Facades\DB;

/**
 * メールアドレスを、列の照合順序で「同じ」とみなされる範囲ごとに1つの値へ写す。
 *
 * ログインの照合（where email = ?）は列の照合順序で比べるので、大文字小文字・全角・無視される文字・
 * 末尾の空白（PAD SPACE）が違っても同じ利用者に当たる。PHP の正規化では同じ範囲を作れず、
 * 足りない側で制限の枠を分けられるので、DB に照合順序の重みを計算させる（MySQL 専用）。
 */
final class MysqlCollationEmailKey implements EmailKey
{
    public function __construct(
        private readonly string $table,
        private readonly string $column,
        private readonly ?string $connection = null,
    ) {}

    /**
     * @throws \RuntimeException 重みを計算できなかったとき。数える前に止め、照合へは進ませない
     */
    public function key(string $email): string
    {
        $connection = DB::connection($this->connection);
        $grammar = $connection->getQueryGrammar();

        // 列の型を持つ NULL と COALESCE すると、結果は列の照合順序になる
        // （接続の SET NAMES に左右されず、列の照合順序が変わっても追従する）
        $column = sprintf(
            '(SELECT %s FROM %s WHERE FALSE)',
            $grammar->wrap($this->column),
            $grammar->wrapTable($this->table),
        );

        // WEIGHT_STRING は末尾の空白の重みを残すが、PAD SPACE の = はそれを無視する。
        // 末尾から空白の重みを取り除いて揃える。使う関数は MySQL 9 にもあるものに限る
        // （SQL の SHA1() は 9 で無くなった）。固定長にするハッシュは PHP 側で取る。
        try {
            $row = $connection->selectOne(
                "SELECT HEX(TRIM(TRAILING WEIGHT_STRING(COALESCE({$column}, ' '))"
                ." FROM WEIGHT_STRING(COALESCE({$column}, ?)))) AS weight",
                [$email],
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('Failed to compute the collation key of the email', 0, $e);
        }

        // 入力が大きすぎる（max_allowed_packet 超過）と WEIGHT_STRING は NULL を返す。
        // 全員が同じ枠に入るのを黙って許さず止める
        if (! is_string($row?->weight ?? null)) {
            throw new \RuntimeException('Failed to compute the collation key of the email');
        }

        return sha1($row->weight);
    }
}
