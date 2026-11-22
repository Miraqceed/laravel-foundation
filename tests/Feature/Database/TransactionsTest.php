<?php

namespace Miraqceed\Foundation\Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;
use Miraqceed\Foundation\Database\Transactions;
use Miraqceed\Foundation\Tests\Support\LockConnections;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * アプリのトランザクションの中か：試しの包み（RefreshDatabase）は外に数える。ほかの接続のトランザクションは数えない
 */
class TransactionsTest extends TestCase
{
    use LockConnections;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $this->closeLockConnections();

        parent::tearDown();
    }

    /** クラスが終わったら移行の状態を戻す（後のクラスが、自分の要る表のない DB をそのまま使わないように） */
    public static function tearDownAfterClass(): void
    {
        RefreshDatabaseState::$migrated = false;

        parent::tearDownAfterClass();
    }

    public function test_the_test_wrapper_alone_counts_as_outside(): void
    {
        $this->assertSame(1, DB::transactionLevel());
        $this->assertFalse(Transactions::inside());
    }

    public function test_an_application_transaction_counts_as_inside(): void
    {
        $inside = DB::transaction(fn (): bool => Transactions::inside());
        $nested = DB::transaction(fn (): bool => DB::transaction(fn (): bool => Transactions::inside()));

        $this->assertTrue($inside);
        $this->assertTrue($nested);
        $this->assertFalse(Transactions::inside());
    }

    public function test_a_transaction_on_another_connection_does_not_count_for_this_one(): void
    {
        $other = $this->otherConnection();
        $other->beginTransaction();

        try {
            $this->assertFalse(Transactions::inside());
            $this->assertTrue(Transactions::inside('lock-other'));
        } finally {
            $other->rollBack();
        }

        $this->assertFalse(Transactions::inside('lock-other'));
    }

    public function test_assert_outside_throws_the_given_reason_inside_and_nothing_outside(): void
    {
        Transactions::assertOutside('外で呼ぶ');

        try {
            DB::transaction(fn () => Transactions::assertOutside('冪等キーは仕事のトランザクションの外で取る'));
            $this->fail('LogicException が要る');
        } catch (LogicException $e) {
            $this->assertSame('冪等キーは仕事のトランザクションの外で取る', $e->getMessage());
        }
    }
}
