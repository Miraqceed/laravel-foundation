<?php

namespace Miraqceed\Foundation\Database;

use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * アプリのトランザクションの中かを見分ける。試しの包み（RefreshDatabase・DatabaseTransactions）は外に数える。
 *
 * transactionLevel() は入れ子を数えるだけで、試しの包みとアプリのトランザクションを分けない。
 * Laravel のトランザクションの記録（db.transactions）は、試しの間、包みの分を callbackApplicableTransactions() から外す
 * （afterCommit() が包みだけのときにすぐ動くのと同じ仕組み）。外す数は接続をまたいで数えるので、接続の名前で絞る。
 * 見えるのは Laravel の beginTransaction()・transaction() で始めたものだけ（PDO で直に BEGIN したものは見えない）
 */
final class Transactions
{
    /** 接続（null は既定）で、アプリのトランザクションの中なら真 */
    public static function inside(?string $connection = null): bool
    {
        $db = DB::connection($connection);

        if ($db->transactionLevel() === 0) {
            return false;
        }

        $manager = app()->bound('db.transactions') ? app('db.transactions') : null;
        if (! $manager instanceof DatabaseTransactionsManager) {
            return true;
        }

        $name = $db->getName();

        return $manager->callbackApplicableTransactions()
            ->contains(fn (DatabaseTransactionRecord $transaction): bool => $transaction->connection === $name);
    }

    /**
     * 中なら LogicException（文は $reason）。
     *
     * @throws LogicException
     */
    public static function assertOutside(string $reason, ?string $connection = null): void
    {
        if (self::inside($connection)) {
            throw new LogicException($reason);
        }
    }
}
