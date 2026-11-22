<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Database\Transactions;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 試しの包みのない試し（DatabaseTruncation の試しと同じ）では、transactionLevel() と同じになる。
 * 容器にトランザクションの記録が無いときも、transactionLevel() に寄せる
 */
class TransactionsWithoutWrapperTest extends TestCase
{
    public function test_without_a_wrapper_it_follows_the_transaction_level(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(Transactions::inside());
        $this->assertTrue(DB::transaction(fn (): bool => Transactions::inside()));
        $this->assertFalse(Transactions::inside());
    }

    public function test_without_the_transaction_records_it_falls_back_to_the_transaction_level(): void
    {
        unset($this->app['db.transactions']);
        $this->assertFalse($this->app->bound('db.transactions'));

        $this->assertFalse(Transactions::inside());
        DB::beginTransaction();
        try {
            $this->assertTrue(Transactions::inside());
        } finally {
            DB::rollBack();
        }
    }
}
