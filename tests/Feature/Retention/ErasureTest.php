<?php

namespace Miraqceed\Foundation\Tests\Feature\Retention;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use Miraqceed\Foundation\Retention\DataMap;
use Miraqceed\Foundation\Retention\Erasure;
use Miraqceed\Foundation\Retention\Events\OwnerErased;
use Miraqceed\Foundation\Retention\MapBuilder;
use Miraqceed\Foundation\Tests\Support\EmailChangeUser;
use Miraqceed\Foundation\Tests\Support\Retention\CreatesRetentionTables;
use Miraqceed\Foundation\Tests\Support\Retention\RetentionAdmin;
use Miraqceed\Foundation\Tests\Support\Retention\ShopDataMap;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 台帳から持ち主を消す：握って確かめ直す・外部キーの子から・書き換え・残す表に触れない・途中で投げたら全部戻る
 */
class ErasureTest extends TestCase
{
    use CreatesRetentionTables;

    private int $alice;

    private int $bob;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.retention.map', ShopDataMap::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRetentionTables();
        $this->createEmailChangeTable();
        $this->alice = $this->seedUser('alice');
        $this->bob = $this->seedUser('bob');
    }

    protected function tearDown(): void
    {
        $this->dropRetentionTables();

        parent::tearDown();
    }

    /** 作り物の利用者と、その人の行（どの表にも） */
    private function seedUser(string $name): int
    {
        $id = (int) DB::table('users')->insertGetId([
            'name' => $name, 'email' => "{$name}@example.test", 'password' => 'hashed', 'remember_token' => 'remember',
            'plan' => 'pro', 'created_at' => '2026-01-01 00:00:00', 'deleted_at' => '2026-02-01 00:00:00',
        ]);
        foreach ([1, 2] as $n) {
            $post = (int) DB::table('posts')->insertGetId(['user_id' => $id, 'title' => "{$name} {$n}"]);
            DB::table('post_images')->insert([['post_id' => $post, 'path' => "{$name}/{$n}a.png"], ['post_id' => $post, 'path' => "{$name}/{$n}b.png"]]);
            DB::table('contacts')->insert(['user_id' => $id, 'email' => "{$name}{$n}@example.test", 'phone' => '090-0000-0000', 'label' => "窓口{$n}"]);
            DB::table('clicks')->insert(['user_id' => $id, 'created_at' => '2026-03-01 00:00:00']);
        }
        DB::table('invoices')->insert(['user_id' => $id, 'amount' => 1000]);
        DB::table('screenshots')->insert(['user_id' => $id, 'path' => "{$name}.png"]);
        $this->emailChange(EmailChangeUser::class, $id, $name);

        return $id;
    }

    private function emailChange(string $type, int $id, string $name): void
    {
        DB::table('foundation_email_changes')->insert([
            'account_type' => $type, 'account_id' => (string) $id,
            'old_email' => "{$name}@example.test", 'new_email' => "{$name}-new@example.test", 'old_email_key' => hash('sha256', $name),
            'token_hash' => hash('sha256', $type.$id), 'expires_at' => '2026-02-01 01:00:00', 'created_at' => '2026-02-01 00:00:00',
        ]);
    }

    private function erasure(): Erasure
    {
        return $this->app->make(Erasure::class);
    }

    /** @return array<string, int> 表ごとの、その持ち主の行の数 */
    private function rowsOf(int $id): array
    {
        $posts = DB::table('posts')->where('user_id', $id)->pluck('id')->all();

        return [
            'posts' => count($posts),
            'post_images' => DB::table('post_images')->whereIn('post_id', $posts === [] ? [0] : $posts)->count(),
            'clicks' => DB::table('clicks')->where('user_id', $id)->count(),
            'invoices' => DB::table('invoices')->where('user_id', $id)->count(),
            'screenshots' => DB::table('screenshots')->where('user_id', $id)->count(),
            'foundation_email_changes' => DB::table('foundation_email_changes')->where('account_id', (string) $id)->count(),
        ];
    }

    private const UNTOUCHED = ['posts' => 2, 'post_images' => 4, 'clicks' => 2, 'invoices' => 1, 'screenshots' => 1, 'foundation_email_changes' => 1];

    public function test_持ち主を握り_台帳のとおりに消して書き換える(): void
    {
        $seen = null;

        $erased = $this->erasure()->erase('user', $this->alice, when: function (object $user) use (&$seen): bool {
            $seen = $user->email;

            return $user->deleted_at !== null;
        });

        $this->assertTrue($erased);
        $this->assertSame('alice@example.test', $seen);
        // 外部キーの子から消し（3段）、kept・handledByApp の表に触れない
        $this->assertSame(['posts' => 0, 'post_images' => 0, 'clicks' => 0, 'invoices' => 1, 'screenshots' => 1, 'foundation_email_changes' => 0], $this->rowsOf($this->alice));
        $this->assertSame(self::UNTOUCHED, $this->rowsOf($this->bob));

        $user = DB::table('users')->where('id', $this->alice)->first();
        $this->assertSame(['退会したユーザー', "deleted-{$this->alice}@anonymized.invalid", '', null, 'pro'], [$user->name, $user->email, $user->password, $user->remember_token, $user->plan]);
        $this->assertSame('bob@example.test', DB::table('users')->where('id', $this->bob)->value('email'));
    }

    public function test_Anonymize_unique_は行ごとに違う値になる(): void
    {
        $this->erasure()->erase('user', $this->alice, when: fn () => true);

        $contacts = DB::table('contacts')->where('user_id', $this->alice)->orderBy('id')->get();
        $this->assertSame(
            $contacts->map(fn ($contact) => "contact-{$contact->id}@anonymized.invalid")->all(),
            $contacts->pluck('email')->all(),
        );
        $this->assertSame([null, null], $contacts->pluck('phone')->all());
        $this->assertSame(['窓口1', '窓口2'], $contacts->pluck('label')->all());
        $this->assertSame(2, DB::table('contacts')->where('user_id', $this->bob)->where('email', 'like', 'bob%@example.test')->count());
    }

    public function test_when_が偽か_持ち主がなければ何も消さない(): void
    {
        $this->assertFalse($this->erasure()->erase('user', $this->alice, when: fn (object $user) => $user->deleted_at === null));
        $this->assertFalse($this->erasure()->erase('user', 999_999, when: fn () => $this->fail('持ち主がないのに呼んだ')));

        $this->assertSame(self::UNTOUCHED, $this->rowsOf($this->alice));
        $this->assertSame('alice@example.test', DB::table('users')->where('id', $this->alice)->value('email'));
    }

    public function test_メールアドレスの変更は_持ち主の表のモデルの行だけを消す(): void
    {
        // 同じ id の、別の表の持ち主（admins）の行
        $this->emailChange(RetentionAdmin::class, $this->alice, 'admin');

        $this->erasure()->erase('user', $this->alice, when: fn () => true);

        $this->assertSame([RetentionAdmin::class], DB::table('foundation_email_changes')->where('account_id', (string) $this->alice)->pluck('account_type')->all());
    }

    public function test_途中で投げたら_全部が巻き戻る(): void
    {
        // 残す表（reviews）が alice の投稿を指す。post_images を消した後、posts を消すところで外部キーに当たる
        $post = DB::table('posts')->where('user_id', $this->alice)->value('id');
        DB::table('reviews')->insert(['post_id' => $post, 'body' => 'よかった']);

        try {
            $this->erasure()->erase('user', $this->alice, when: fn () => true);
            $this->fail('投げなかった');
        } catch (QueryException) {
        }

        $this->assertSame(self::UNTOUCHED, $this->rowsOf($this->alice));
        $this->assertSame('alice@example.test', DB::table('users')->where('id', $this->alice)->value('email'));
    }

    public function test_出来事は確定の後に_表ごとの数を持つ(): void
    {
        Event::fake([OwnerErased::class]);

        DB::transaction(function (): void {
            $this->erasure()->erase('user', $this->alice, when: fn () => true);

            Event::assertNotDispatched(OwnerErased::class);
        });

        Event::assertDispatched(OwnerErased::class, fn (OwnerErased $event) => $event->owner === 'user' && $event->id === $this->alice
            && $event->counts === ['clicks' => 2, 'foundation_email_changes' => 1, 'post_images' => 4, 'posts' => 2, 'contacts' => 2, 'users' => 1]);
    }

    public function test_巻き戻れば出来事は出ない(): void
    {
        Event::fake([OwnerErased::class]);

        try {
            DB::transaction(function (): void {
                $this->erasure()->erase('user', $this->alice, when: fn () => true);

                throw new \RuntimeException('rolled back');
            });
        } catch (\RuntimeException) {
        }

        Event::assertNotDispatched(OwnerErased::class);
        $this->assertSame(self::UNTOUCHED, $this->rowsOf($this->alice));
    }

    public function test_台帳がない_知らない持ち主_輪になる順は_LogicException(): void
    {
        foreach ([
            null,
            ShopDataMap::class.'@team',
            get_class(new class implements DataMap
            {
                public function define(MapBuilder $map): void
                {
                    $map->owner('user', table: 'users');
                    $map->table('users')->kept('試し');
                    $map->table('posts')->erasedWith('user', via: ['post_images', 'post_id', 'path']);
                    $map->table('post_images')->erasedWith('user', via: ['posts', 'post_id', 'user_id']);
                }
            }),
        ] as $map) {
            [$class, $owner] = array_pad(explode('@', (string) $map), 2, 'user');
            config(['foundation.retention.map' => $class === '' ? null : $class]);

            try {
                $this->erasure()->erase($owner, $this->alice, when: fn () => true);
                $this->fail("{$map}：投げなかった");
            } catch (LogicException) {
            }
        }

        $this->assertSame(self::UNTOUCHED, $this->rowsOf($this->alice));
    }
}
