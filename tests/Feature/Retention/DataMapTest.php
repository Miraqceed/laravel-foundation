<?php

namespace Miraqceed\Foundation\Tests\Feature\Retention;

use InvalidArgumentException;
use LogicException;
use Miraqceed\Foundation\Accounts\EmailChange;
use Miraqceed\Foundation\Notices\Notice;
use Miraqceed\Foundation\Retention\Anonymize;
use Miraqceed\Foundation\Retention\DataMap;
use Miraqceed\Foundation\Retention\MapBuilder;
use Miraqceed\Foundation\Retention\ResolvedMap;
use Miraqceed\Foundation\Tests\Support\Retention\Click;
use Miraqceed\Foundation\Tests\Support\Retention\ShopDataMap;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 台帳の組み立て：規則が読める・パッケージの表の分を足す・アプリが勝つ・書き違いは LogicException
 */
class DataMapTest extends TestCase
{
    /** @param  \Closure(MapBuilder): void  $define */
    private static function map(\Closure $define): DataMap
    {
        return new class($define) implements DataMap
        {
            public function __construct(private readonly \Closure $define) {}

            public function define(MapBuilder $map): void
            {
                ($this->define)($map);
            }
        };
    }

    public function test_組み立てた規則が読める(): void
    {
        $map = ResolvedMap::from(new ShopDataMap);

        $this->assertSame(['user' => ['table' => 'users', 'key' => 'id']], $map->owners());
        $tables = $map->tables();
        $this->assertSame('user_id', $tables['posts']->erasures('user')[0]['column']);
        $this->assertSame(['table' => 'posts', 'column' => 'post_id', 'ownerColumn' => 'user_id', 'key' => 'id'], $tables['post_images']->erasures('user')[0]['via']);
        $this->assertSame([Click::class], $tables['clicks']->models());
        $this->assertSame(['queue:prune-failed'], $tables['failed_jobs']->commands());
        $this->assertTrue($tables['invoices']->isKept());
        $this->assertSame('ScreenshotStore::purge（ストレージの画像も消す）', $tables['screenshots']->handledBy());
        $this->assertSame(['id', 'plan', 'created_at', 'deleted_at'], $tables['users']->anonymization()['keep']);
    }

    public function test_パッケージの表の分を足し_有効でない部品の表は数えない(): void
    {
        config(['foundation.notices.enabled' => true]);

        $map = ResolvedMap::from(new ShopDataMap);

        $this->assertSame([Notice::class], $map->tables(lookAtDatabase: false)['foundation_notices']->models());
        // 有効でない部品（表があるかは、ほかの試しの残りで変わるので DB を見ない）
        $this->assertArrayNotHasKey('foundation_one_time_codes', $map->tables(lookAtDatabase: false));
        $this->assertArrayNotHasKey('foundation_alerts', $map->tables(lookAtDatabase: false));
        $this->assertArrayHasKey('foundation_one_time_codes', $map->allTables());
    }

    public function test_メールアドレスの変更の表は_期限と_アプリのすべての持ち主の消去で消す(): void
    {
        $map = ResolvedMap::from(self::map(function (MapBuilder $map): void {
            $map->owner('user', table: 'users');
            $map->owner('member', table: 'members');
            $map->table('users')->erasedWith('user', column: 'id');
            $map->table('members')->erasedWith('member', column: 'id');
        }));

        $rule = $map->allTables()['foundation_email_changes'];
        $this->assertSame([EmailChange::class], $rule->models());
        $this->assertSame(['user', 'member'], array_column($rule->erasures(), 'owner'));
        $this->assertSame(['account', 'account'], array_column($rule->erasures(), 'morph'));
    }

    public function test_アプリが同じ表を書けば_アプリの規則が勝つ(): void
    {
        config(['foundation.notices.enabled' => true]);

        $map = ResolvedMap::from(self::map(function (MapBuilder $map): void {
            $map->table('foundation_notices')->kept('知らせの印を、監査のために残す');
        }));

        $rule = $map->tables()['foundation_notices'];
        $this->assertTrue($rule->isKept());
        $this->assertSame([], $rule->models());
        $this->assertNull($rule->component());
    }

    public function test_設定がなければ台帳なし_DataMap_でないクラスは_LogicException(): void
    {
        $this->assertNull(ResolvedMap::fromConfig());

        config(['foundation.retention.map' => ShopDataMap::class]);
        $this->assertInstanceOf(ResolvedMap::class, ResolvedMap::fromConfig());

        config(['foundation.retention.map' => Click::class]);
        $this->expectException(LogicException::class);
        ResolvedMap::fromConfig();
    }

    public function test_書いていない持ち主は_LogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('書いていない持ち主 team');

        ResolvedMap::from(self::map(fn (MapBuilder $map) => $map->table('posts')->erasedWith('team', column: 'team_id')));
    }

    public function test_消し方のない表は_LogicException(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('台帳の表 posts に消し方がありません');

        ResolvedMap::from(self::map(fn (MapBuilder $map) => $map->table('posts')));
    }

    /**
     * @return array<string, array{\Closure(MapBuilder): mixed, class-string<\Throwable>}>
     */
    public static function 書き違い(): array
    {
        return [
            '同じ列を erase と keep の両方に' => [fn (MapBuilder $map) => $map->table('users')
                ->anonymizedWith('user', key: 'id', erase: ['email' => null], keep: ['id', 'email'], reason: '集計'), LogicException::class],
            '残す理由が空' => [fn (MapBuilder $map) => $map->table('users')
                ->anonymizedWith('user', key: 'id', erase: ['email' => null], keep: ['id'], reason: ' '), InvalidArgumentException::class],
            '同じ持ち主で消すと書き換える' => [fn (MapBuilder $map) => $map->table('users')
                ->erasedWith('user', column: 'id')->anonymizedWith('user', key: 'id', erase: ['email' => null], keep: ['id'], reason: '集計'), LogicException::class],
            'kept とほかの消し方' => [fn (MapBuilder $map) => $map->table('invoices')->kept('法令')->erasedWith('user', column: 'user_id'), LogicException::class],
            '消し方の後に kept' => [fn (MapBuilder $map) => $map->table('clicks')->prunedBy(Click::class)->kept('法令'), LogicException::class],
            'handledByApp と持ち主の消去' => [fn (MapBuilder $map) => $map->table('files')->handledByApp('FileStore')->erasedWith('user', column: 'user_id'), LogicException::class],
            'column と via の両方' => [fn (MapBuilder $map) => $map->table('post_images')
                ->erasedWith('user', column: 'user_id', via: ['posts', 'post_id', 'user_id']), InvalidArgumentException::class],
            'via が短い' => [fn (MapBuilder $map) => $map->table('post_images')->erasedWith('user', via: ['posts', 'post_id']), InvalidArgumentException::class],
            'prunable のないクラス' => [fn (MapBuilder $map) => $map->table('clicks')->prunedBy(ShopDataMap::class), LogicException::class],
            'コマンドに引数' => [fn (MapBuilder $map) => $map->table('failed_jobs')->prunedByCommand('queue:prune-failed --hours=48'), InvalidArgumentException::class],
            '持ち主を2回' => [fn (MapBuilder $map) => $map->owner('user', table: 'users')->owner('user', table: 'members'), LogicException::class],
            'unique に {id} がない' => [fn () => Anonymize::unique('deleted@anonymized.invalid'), InvalidArgumentException::class],
        ];
    }

    /**
     * @param  \Closure(MapBuilder): mixed  $write
     * @param  class-string<\Throwable>  $exception
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('書き違い')]
    public function test_書き違いは例外(\Closure $write, string $exception): void
    {
        $this->expectException($exception);

        $write(new MapBuilder);
    }
}
