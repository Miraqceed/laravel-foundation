<?php

namespace Miraqceed\Foundation\Tests\Feature\Notices;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Miraqceed\Foundation\FoundationServiceProvider;
use Miraqceed\Foundation\Notices\Notice;
use Miraqceed\Foundation\Notices\NoticeClaim;
use Miraqceed\Foundation\Notices\NoticeResult;
use Miraqceed\Foundation\Notices\OnceNotices;
use Miraqceed\Foundation\Tests\Support\RefreshesNoticeDatabase;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

/**
 * 知らせを1回だけ送る：取る・送れなければ戻す・止まった印の取り直し・キューの取り・トランザクションの中・期限で消す
 */
class OnceNoticesTest extends TestCase
{
    use RefreshesNoticeDatabase;

    private const KEY = 'usage-80:42:2026-03';

    private string $defaultTimezone;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.notices.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultTimezone = date_default_timezone_get();
        // 時刻は UTC で持つ。アプリの時間帯が UTC でなくても、取り直しの分と期限は UTC で比べる
        config(['app.timezone' => 'Asia/Tokyo']);
        date_default_timezone_set('Asia/Tokyo');
        $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);

        parent::tearDown();
    }

    private function notices(): OnceNotices
    {
        return $this->app->make(OnceNotices::class);
    }

    /** 送った回数を数える関数 */
    private function counter(int &$calls): \Closure
    {
        return function () use (&$calls): void {
            $calls++;
        };
    }

    /** @return object{key: string, token: string, claimed_at: string, sent_at: ?string, keep_until: string} */
    private function row(string $key = self::KEY): object
    {
        $row = DB::table(OnceNotices::TABLE)->where('key_hash', hash('sha256', $key))->first();
        $this->assertNotNull($row, "{$key} の印がない");

        return $row;
    }

    public function test_同じ鍵で2回送っても_関数は1回だけ呼ばれる(): void
    {
        $calls = 0;

        $this->assertSame(NoticeResult::Sent, $this->notices()->send(self::KEY, $this->counter($calls)));
        $this->assertSame(NoticeResult::AlreadySent, $this->notices()->send(self::KEY, $this->counter($calls)));

        $this->assertSame(1, $calls);
        $this->assertTrue($this->notices()->has(self::KEY));
        $this->assertSame('2026-03-20 12:00:00', $this->row()->sent_at);
        // 別の回の鍵は別の知らせ
        $this->assertSame(NoticeResult::Sent, $this->notices()->send('usage-80:42:2026-04', $this->counter($calls)));
        $this->assertSame(2, $calls);
    }

    public function test_送れなければ印を戻して_Failed_次の送りで送る(): void
    {
        Exceptions::fake();

        $result = $this->notices()->send(self::KEY, function (): void {
            throw new RuntimeException('smtp refused alice@example.com');
        });

        $this->assertSame(NoticeResult::Failed, $result);
        $this->assertFalse($this->notices()->has(self::KEY));
        // 記録には鍵と例外の種類だけ。関数の例外の文（メールアドレス）は入れない
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), self::KEY)
            && str_contains($e->getMessage(), 'RuntimeException')
            && ! str_contains($e->getMessage(), 'alice@example.com')
            && $e->getPrevious() === null);

        $calls = 0;
        $this->assertSame(NoticeResult::Sent, $this->notices()->send(self::KEY, $this->counter($calls)));
        $this->assertSame(1, $calls);
    }

    public function test_keepOnFailure_なら_送れなくても印を残し_取り直しの分の後も送らない(): void
    {
        Exceptions::fake();

        $result = $this->notices()->send(self::KEY, fn () => throw new RuntimeException('timeout after accepted'), keepOnFailure: true);

        $this->assertSame(NoticeResult::Failed, $result);
        $this->assertTrue($this->notices()->has(self::KEY));
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), self::KEY));

        $this->travel(60)->minutes();
        $calls = 0;
        $this->assertSame(NoticeResult::AlreadySent, $this->notices()->send(self::KEY, $this->counter($calls)));
        $this->assertSame(0, $calls);
    }

    public function test_取ったまま止まった印は_取り直しの分を過ぎると取り直す(): void
    {
        // 取った後にプロセスが止まった（送っていない）
        $this->assertNotNull($this->notices()->claim(self::KEY));
        $calls = 0;

        $this->travel(14)->minutes();
        $this->assertSame(NoticeResult::AlreadySent, $this->notices()->send(self::KEY, $this->counter($calls)));

        $this->travel(1)->minutes();
        $this->assertSame(NoticeResult::Sent, $this->notices()->send(self::KEY, $this->counter($calls)));
        $this->assertSame(1, $calls);
    }

    public function test_取り直しの分は_設定と_claimMinutes_で変えられる(): void
    {
        config(['foundation.notices.claim_minutes' => 30]);
        $this->notices()->claim(self::KEY);
        $calls = 0;

        $this->travel(15)->minutes();
        $this->assertSame(NoticeResult::AlreadySent, $this->notices()->send(self::KEY, $this->counter($calls)));
        $this->assertSame(NoticeResult::Sent, $this->notices()->send(self::KEY, $this->counter($calls), claimMinutes: 15));
        $this->assertSame(1, $calls);

        $this->notices()->claim('other');
        $this->travel(5)->minutes();
        $this->assertNull($this->notices()->claim('other', claimMinutes: 6));
        $this->assertNotNull($this->notices()->claim('other', claimMinutes: 5));
    }

    public function test_送っている間の同じ鍵の送りは_AlreadySent(): void
    {
        $inner = null;

        $this->notices()->send(self::KEY, function () use (&$inner): void {
            $inner = $this->notices()->send(self::KEY, fn () => $this->fail('内側で送った'));
        });

        $this->assertSame(NoticeResult::AlreadySent, $inner);
    }

    public function test_トランザクションの中では_確定の後に取って送る(): void
    {
        $calls = 0;

        $result = DB::transaction(function () use (&$calls): NoticeResult {
            $result = $this->notices()->send(self::KEY, $this->counter($calls));

            // 中では表に書かず、送らない
            $this->assertSame(0, DB::table(OnceNotices::TABLE)->count());
            $this->assertSame(0, $calls);

            return $result;
        });

        $this->assertSame(NoticeResult::Deferred, $result);
        $this->assertSame(1, $calls);
        $this->assertNotNull($this->row()->sent_at);
    }

    public function test_トランザクションが巻き戻れば_取らず送らない(): void
    {
        $calls = 0;

        try {
            DB::transaction(function () use (&$calls): void {
                $this->notices()->send(self::KEY, $this->counter($calls));

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, $calls);
        $this->assertFalse($this->notices()->has(self::KEY));
    }

    public function test_確定の後にほかの処理が先に取っていれば_送らない(): void
    {
        $calls = 0;

        DB::transaction(function () use (&$calls): void {
            // 確定の後に、この送りより先に、ほかの処理が取る
            DB::afterCommit(fn () => $this->notices()->claim(self::KEY));

            $this->assertSame(NoticeResult::Deferred, $this->notices()->send(self::KEY, $this->counter($calls)));
        });

        $this->assertSame(0, $calls);
        $this->assertNull($this->row()->sent_at);
    }

    public function test_確定の後に送れなくても_投げずに印を戻して記録する(): void
    {
        Exceptions::fake();

        DB::transaction(function (): void {
            $this->notices()->send(self::KEY, fn () => throw new RuntimeException('smtp refused'));
        });

        $this->assertFalse($this->notices()->has(self::KEY));
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), self::KEY));
    }

    public function test_キュー_取った印は自分の_token_のときだけ_holds_sent_release_が効く(): void
    {
        $claim = $this->notices()->claim(self::KEY);
        $this->assertInstanceOf(NoticeClaim::class, $claim);
        $this->assertNull($this->notices()->claim(self::KEY));

        $other = new NoticeClaim(self::KEY, str_repeat('0', 32));
        $this->assertFalse($other->holds());
        $this->assertFalse($other->sent());
        $this->assertFalse($other->release());
        $this->assertTrue($this->notices()->has(self::KEY));

        // 同じ秒に取って確かめても真（MySQL は値の変わらない行を数えない）
        $this->assertTrue($claim->holds());
        $this->assertTrue($claim->sent());
        $this->assertFalse($claim->release());
        $this->assertFalse($claim->holds());
        $this->assertNotNull($this->row()->sent_at);
    }

    public function test_キュー_release_で印を戻すと_次の確かめで取れる(): void
    {
        $claim = $this->notices()->claim(self::KEY);

        $this->assertTrue($claim->release());

        $this->assertFalse($this->notices()->has(self::KEY));
        $this->assertNotNull($this->notices()->claim(self::KEY));
    }

    public function test_キュー_holds_は取った時刻を延ばす(): void
    {
        $claim = $this->notices()->claim(self::KEY);

        $this->travel(10)->minutes();
        $this->assertTrue($claim->holds());
        $this->assertSame('2026-03-20 12:10:00', $this->row()->claimed_at);

        // 初めに取ってから20分。延ばしてからは10分なので、取り直さない
        $this->travel(10)->minutes();
        $this->assertNull($this->notices()->claim(self::KEY));
    }

    public function test_キュー_取り直された後の前のジョブは_holds_が偽で送らない(): void
    {
        $old = $this->notices()->claim(self::KEY);

        // 前のジョブがキューで待つ間に、取り直しの分を過ぎ、次の確かめが取り直した
        $this->travel(16)->minutes();
        $new = $this->notices()->claim(self::KEY);
        $this->assertNotNull($new);

        $this->assertFalse($old->holds());
        $this->assertFalse($old->sent());
        $this->assertFalse($old->release());
        $this->assertTrue($new->holds());
        $this->assertTrue($new->sent());
    }

    public function test_キュー_取った印はジョブに渡せる(): void
    {
        $claim = unserialize(serialize($this->notices()->claim(self::KEY)));

        $this->assertInstanceOf(NoticeClaim::class, $claim);
        $this->assertTrue($claim->holds());
    }

    public function test_forget_の後は_また送る(): void
    {
        $calls = 0;
        $this->notices()->send(self::KEY, $this->counter($calls));

        $this->notices()->forget(self::KEY);

        $this->assertFalse($this->notices()->has(self::KEY));
        $this->assertSame(NoticeResult::Sent, $this->notices()->send(self::KEY, $this->counter($calls)));
        $this->assertSame(2, $calls);
    }

    public function test_鍵は_255字まで(): void
    {
        $key = str_repeat('鍵', 255);
        $this->assertSame(NoticeResult::Sent, $this->notices()->send($key, fn () => null));
        $this->assertSame($key, $this->row($key)->key);

        foreach ([str_repeat('鍵', 256), '', "\xff"] as $bad) {
            try {
                $this->notices()->send($bad, fn () => $this->fail('送った'));
                $this->fail('投げなかった');
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame(1, DB::table(OnceNotices::TABLE)->count());
    }

    public function test_大文字と小文字の違う鍵は_別の知らせ(): void
    {
        $this->assertSame(NoticeResult::Sent, $this->notices()->send('Notice:1', fn () => null));
        $this->assertSame(NoticeResult::Sent, $this->notices()->send('notice:1', fn () => null));
    }

    public function test_印の期限は_既定で取ってから_keep_days_日_渡せば_UTC_で書く(): void
    {
        $this->notices()->send('default', fn () => null);
        $this->notices()->send('given', fn () => null, keepUntil: CarbonImmutable::parse('2026-05-01 09:00:00', 'Asia/Tokyo'));

        $this->assertSame('2027-04-24 12:00:00', $this->row('default')->keep_until);
        $this->assertSame('2026-05-01 00:00:00', $this->row('given')->keep_until);
    }

    public function test_model_prune_は期限を過ぎた印だけを消す(): void
    {
        $this->notices()->send('past', fn () => null, keepUntil: CarbonImmutable::parse('2026-03-20 11:59:59', 'UTC'));
        // アプリの時間帯（9時間東）に寄せて比べると、誤って消える
        $this->notices()->send('soon', fn () => null, keepUntil: CarbonImmutable::parse('2026-03-20 16:00:00', 'UTC'));
        $this->notices()->send('default', fn () => null);

        Artisan::call('model:prune', ['--model' => [Notice::class]]);

        $this->assertSame(['default', 'soon'], DB::table(OnceNotices::TABLE)->orderBy('key')->pluck('key')->all());
    }

    public function test_印の_token_はモデルの配列に出さない(): void
    {
        $this->notices()->send(self::KEY, fn () => null);

        $this->assertArrayNotHasKey('token', Notice::query()->firstOrFail()->toArray());
    }

    public function test_マイグレーションは写せる(): void
    {
        $paths = ServiceProvider::pathsToPublish(FoundationServiceProvider::class, 'foundation-notice-migrations');

        $this->assertCount(1, $paths);
        $this->assertDirectoryExists((string) array_key_first($paths));
    }
}
