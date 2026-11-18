<?php

namespace Miraqceed\Foundation\Tests\Feature\Accounts;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Miraqceed\Foundation\Accounts\EmailChange;
use Miraqceed\Foundation\Accounts\EmailChanges;
use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Tests\Support\CreatesEmailChangeTables;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 表は設定が偽なら読み込まない（入れただけでは作らない）・写せる・もう使えない行だけを model:prune で消す
 */
class EmailChangeTableTest extends TestCase
{
    use CreatesEmailChangeTables;

    protected function tearDown(): void
    {
        $this->dropEmailChangeTables();

        parent::tearDown();
    }

    public function test_設定が偽ならマイグレーションを読み込まない(): void
    {
        // 表の有無は、ほかの試しが作った DB の残りで変わる。マイグレーションの置き場が登録されないことで確かめる
        $paths = array_filter(app('migrator')->paths(), fn ($path) => str_contains($path, 'email-change-migrations'));

        $this->assertSame([], array_values($paths));
    }

    public function test_マイグレーションを写せる(): void
    {
        $paths = ServiceProvider::pathsToPublish(FoundationServiceProvider::class, 'foundation-email-change-migrations');

        $this->assertCount(1, $paths);
        $this->assertStringEndsWith('email-change-migrations', (string) array_key_first($paths));
        $this->assertDirectoryExists((string) array_key_first($paths));
    }

    public function test_もう使えない行だけを_prune_days_の後に消す(): void
    {
        $this->createEmailChangeTables();
        $this->travelTo('2026-03-20 12:00:00');
        $old = '2026-03-12 11:59:59';
        $recent = '2026-03-14 12:00:00';
        $future = '2026-03-25 12:00:00';
        $rows = [
            'expired-pending' => ['expires_at' => $old],
            'recent-pending' => ['expires_at' => $recent],
            'cancelled' => ['expires_at' => $future, 'cancelled_at' => $old],
            'undone' => ['expires_at' => $old, 'confirmed_at' => $old, 'undone_at' => $old, 'undo_expires_at' => $future],
            'undo-window-over' => ['expires_at' => $old, 'confirmed_at' => $old, 'undo_expires_at' => $old],
            // 戻せる間（押さえ）は、頼みの期限が古くても消さない
            'held' => ['expires_at' => $old, 'confirmed_at' => $old, 'undo_expires_at' => $future],
        ];
        foreach ($rows as $name => $times) {
            DB::table(EmailChanges::TABLE)->insert([
                'account_type' => 'user', 'account_id' => '1', 'old_email' => $name.'@example.test', 'new_email' => 'n@example.test',
                'old_email_key' => sha1($name), 'token_hash' => hash('sha256', $name), 'created_at' => $old, ...$times,
            ]);
        }

        Artisan::call('model:prune', ['--model' => [EmailChange::class]]);

        $this->assertEqualsCanonicalizing(
            ['recent-pending@example.test', 'held@example.test'],
            DB::table(EmailChanges::TABLE)->pluck('old_email')->all(),
        );
    }
}
