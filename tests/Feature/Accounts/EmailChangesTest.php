<?php

namespace Miraqceed\Foundation\Tests\Feature\Accounts;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Miraqceed\Foundation\Accounts\EmailAvailability;
use Miraqceed\Foundation\Accounts\EmailChanges;
use Miraqceed\Foundation\Accounts\EmailChangeStatus;
use Miraqceed\Foundation\Accounts\Events\EmailChangeConfirmed;
use Miraqceed\Foundation\Accounts\Events\EmailChangeUndone;
use Miraqceed\Foundation\Accounts\RequestedEmailChange;
use Miraqceed\Foundation\Accounts\Rules\NotHeldEmail;
use Miraqceed\Foundation\Tests\Support\CreatesEmailChangeTables;
use Miraqceed\Foundation\Tests\Support\EmailChangeUser;
use Miraqceed\Foundation\Tests\Support\LowercaseEmailChangeUser;
use Miraqceed\Foundation\Tests\Support\RecordingLockdown;
use Miraqceed\Foundation\Tests\TestCase;
use RuntimeException;

/**
 * メールアドレスの変更：頼む・確かめる（本人のログインの中だけ）・戻す（たどって戻し、乗っ取った人のものを消す）・押さえ。
 * アドレス・トークンはすべて作り物
 */
class EmailChangesTest extends TestCase
{
    use CreatesEmailChangeTables;

    private EmailChanges $changes;

    private EmailChangeUser $alice;

    private EmailChangeUser $bob;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', EmailChangeUser::class);
        $app['config']->set('session.driver', 'database');
        $app['config']->set('hashing.bcrypt.rounds', 4);
        $app['config']->set('foundation.email_changes.enabled', true);
        $app['config']->set('foundation.email_changes.lockdown', RecordingLockdown::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createEmailChangeTables();
        RecordingLockdown::reset();
        $this->travelTo('2026-03-01 12:00:00');

        $this->changes = $this->app->make(EmailChanges::class);
        $this->alice = EmailChangeUser::create(['email' => 'alice@example.test', 'email_verified_at' => null, 'password' => Hash::make('alice-password'), 'remember_token' => 'alice-old-token']);
        $this->bob = EmailChangeUser::create(['email' => 'bob@example.test', 'password' => Hash::make('bob-password'), 'remember_token' => 'bob-old-token']);
    }

    protected function tearDown(): void
    {
        $this->dropEmailChangeTables();
        RecordingLockdown::reset();

        parent::tearDown();
    }

    private function confirm(RequestedEmailChange $requested, ?EmailChangeUser $as = null): EmailChangeStatus
    {
        return $this->changes->confirm($requested->id, $requested->token, $as ?? $this->alice->fresh())->status;
    }

    /** A → $email に変えて確かめ、戻すリンクの id とトークンを返す */
    private function changeAliceTo(string $email): array
    {
        $requested = $this->changes->request($this->alice->fresh(), $email);
        $result = $this->changes->confirm($requested->id, $requested->token, $this->alice->fresh());
        $this->assertTrue($result->confirmed(), $result->status->value);

        return [$requested->id, $result->undoToken];
    }

    private function aliceEmail(): string
    {
        return (string) $this->alice->fresh()->email;
    }

    // 頼む・確かめる

    public function test_確かめる前は切り替わらず_確かめると切り替わり_戻すトークンが出る(): void
    {
        $requested = $this->changes->request($this->alice, 'alice.new@example.test');

        $this->assertMatchesRegularExpression('/\Aec_[A-Za-z0-9]{40}\z/', $requested->token);
        $this->assertSame('alice.new@example.test', $requested->newEmail);
        $this->assertSame('2026-03-01 13:00:00', $requested->expiresAt->format('Y-m-d H:i:s'));
        $this->assertSame('alice@example.test', $this->aliceEmail());

        $result = $this->changes->confirm($requested->id, $requested->token, $this->alice);

        $this->assertTrue($result->confirmed());
        $this->assertSame('alice@example.test', $result->oldEmail);
        $this->assertSame('alice.new@example.test', $result->newEmail);
        $this->assertMatchesRegularExpression('/\Aecu_[A-Za-z0-9]{40}\z/', (string) $result->undoToken);
        $this->assertSame('2026-03-08 12:00:00', $result->undoExpiresAt?->format('Y-m-d H:i:s'));
        $this->assertSame('alice.new@example.test', $this->aliceEmail());
        $this->assertNotNull($this->alice->fresh()->email_verified_at);
    }

    public function test_表に平文のトークンを書かない(): void
    {
        $requested = $this->changes->request($this->alice, 'alice.new@example.test');
        $result = $this->changes->confirm($requested->id, $requested->token, $this->alice);

        $row = (array) DB::table(EmailChanges::TABLE)->first();
        $stored = json_encode($row);
        $this->assertStringNotContainsString($requested->token, (string) $stored);
        $this->assertStringNotContainsString((string) $result->undoToken, (string) $stored);
        $this->assertSame(hash('sha256', $requested->token), $row['token_hash']);
    }

    public function test_頼み直すと前の頼みは取り消される(): void
    {
        $first = $this->changes->request($this->alice, 'first@example.test');
        $second = $this->changes->request($this->alice, 'second@example.test');

        $this->assertSame(EmailChangeStatus::Cancelled, $this->confirm($first));
        $this->assertSame(EmailChangeStatus::Confirmed, $this->confirm($second));
        $this->assertSame('second@example.test', $this->aliceEmail());
    }

    public function test_形が違うトークンは_DB_を引かずに_invalid(): void
    {
        $requested = $this->changes->request($this->alice, 'alice.new@example.test');

        DB::enableQueryLog();
        foreach ([null, 123, '', 'ec_short', 'ecu_'.str_repeat('a', 40), $requested->token."\n", ['x']] as $token) {
            $this->assertSame(EmailChangeStatus::Invalid, $this->changes->confirm($requested->id, $token, $this->alice)->status);
        }
        foreach (['0', '-1', 'abc', 1.5, null] as $id) {
            $this->assertSame(EmailChangeStatus::Invalid, $this->changes->confirm($id, $requested->token, $this->alice)->status);
        }
        $this->assertSame([], DB::getQueryLog());
    }

    public function test_違うトークン_無い行は_invalid(): void
    {
        $requested = $this->changes->request($this->alice, 'alice.new@example.test');

        $this->assertSame(EmailChangeStatus::Invalid, $this->changes->confirm($requested->id, 'ec_'.str_repeat('A', 40), $this->alice)->status);
        $this->assertSame(EmailChangeStatus::Invalid, $this->changes->confirm($requested->id + 1, $requested->token, $this->alice)->status);
        $this->assertSame('alice@example.test', $this->aliceEmail());
    }

    public function test_未ログイン_別のアカウントでは切り替わらず_切れた_使ったも見せない(): void
    {
        $requested = $this->changes->request($this->alice, 'alice.new@example.test');

        $this->assertSame(EmailChangeStatus::NotRequester, $this->changes->confirm($requested->id, $requested->token, null)->status);
        $this->assertSame(EmailChangeStatus::NotRequester, $this->confirm($requested, $this->bob));

        $this->travel(61)->minutes();
        $this->assertSame(EmailChangeStatus::NotRequester, $this->confirm($requested, $this->bob));
        $this->assertSame('alice@example.test', $this->aliceEmail());
    }

    public function test_期限ちょうどから_expired_確かめた後は_used(): void
    {
        $late = $this->changes->request($this->alice, 'late@example.test');
        $this->travel(60)->minutes();
        $this->assertSame(EmailChangeStatus::Expired, $this->confirm($late));

        $requested = $this->changes->request($this->alice, 'alice.new@example.test');
        $this->assertSame(EmailChangeStatus::Confirmed, $this->confirm($requested));
        $this->assertSame(EmailChangeStatus::Used, $this->confirm($requested));
    }

    public function test_頼んだ後に今のアドレスが変わると_changed(): void
    {
        $requested = $this->changes->request($this->alice, 'alice.new@example.test');
        $this->alice->forceFill(['email' => 'elsewhere@example.test'])->save();

        $this->assertSame(EmailChangeStatus::Changed, $this->confirm($requested));
        $this->assertSame('elsewhere@example.test', $this->aliceEmail());
    }

    public function test_新しいアドレスがその間に使われた_押さえられたら_切り替えない(): void
    {
        $taken = $this->changes->request($this->alice, 'taken@example.test');
        EmailChangeUser::create(['email' => 'taken@example.test', 'password' => 'x']);
        $this->assertSame(EmailChangeStatus::Taken, $this->confirm($taken));

        // ボブが bob@ から変え、bob@ はボブの押さえになる
        $bobs = $this->changes->request($this->bob, 'bob.new@example.test');
        $this->assertSame(EmailChangeStatus::Confirmed, $this->confirm($bobs, $this->bob));
        $held = $this->changes->request($this->alice->fresh(), 'bob@example.test');
        $this->assertSame(EmailChangeStatus::Held, $this->confirm($held));
        $this->assertSame('alice@example.test', $this->aliceEmail());
    }

    public function test_一意の索引の違反は巻き戻して_taken(): void
    {
        // 空きの判定をすり抜けさせ、同時の登録が先に書いた形を作る
        $this->app->instance(NeverTakenAvailability::class, new NeverTakenAvailability);
        config(['foundation.email_changes.availability' => NeverTakenAvailability::class]);
        EmailChangeUser::create(['email' => 'race@example.test', 'password' => 'x']);

        $requested = $this->changes->request($this->alice, 'race@example.test');

        $this->assertSame(EmailChangeStatus::Taken, $this->confirm($requested));
        $this->assertSame('alice@example.test', $this->aliceEmail());
        $this->assertNull(DB::table(EmailChanges::TABLE)->value('confirmed_at'));
    }

    public function test_確かめると古いアドレスの再設定のトークンを消し_出来事を出す(): void
    {
        Event::fake([EmailChangeConfirmed::class]);
        Password::broker()->createToken($this->alice);
        $requested = $this->changes->request($this->alice, 'alice.new@example.test');

        $this->confirm($requested);

        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        Event::assertDispatched(EmailChangeConfirmed::class, function (EmailChangeConfirmed $event) use ($requested): bool {
            return $event->changeId === $requested->id
                && $event->oldEmail === 'alice@example.test'
                && $event->newEmail === 'alice.new@example.test'
                && (int) $event->account->getKey() === $this->alice->id;
        });
        $this->assertSame(['linked' => ['google:'.$this->alice->id]], json_decode((string) DB::table(EmailChanges::TABLE)->value('snapshot'), true));
    }

    public function test_新しいアドレスが壊れていれば頼めない(): void
    {
        foreach (['', str_repeat('a', 250).'@x.test', "\xff@example.test"] as $email) {
            try {
                $this->changes->request($this->alice, $email);
                $this->fail('投げなかった');
            } catch (InvalidArgumentException $e) {
                $this->assertStringNotContainsString('@', $e->getMessage());
            }
        }
    }

    public function test_cancelPending_はまだ確かめていない頼みを取り消す(): void
    {
        $requested = $this->changes->request($this->alice, 'alice.new@example.test');

        $this->assertSame(1, $this->changes->cancelPending($this->alice));
        $this->assertSame(0, $this->changes->cancelPending($this->alice));
        $this->assertSame(EmailChangeStatus::Cancelled, $this->confirm($requested));
    }

    // 戻す

    public function test_戻すと古いアドレスに戻り_パスワード_remember_セッション_再設定のトークン_頼みが消え_差し込みが呼ばれる(): void
    {
        Event::fake([EmailChangeUndone::class]);
        [$id, $undoToken] = $this->changeAliceTo('attacker@example.test');
        // 乗っ取った人のセッション・再設定のトークン・次の頼み
        DB::table('sessions')->insert(['id' => 'attacker-laptop', 'user_id' => $this->alice->id, 'payload' => '', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'bob-phone', 'user_id' => $this->bob->id, 'payload' => '', 'last_activity' => time()]);
        Password::broker()->createToken($this->alice->fresh());
        $pending = $this->changes->request($this->alice->fresh(), 'attacker2@example.test');

        $result = $this->changes->undo($id, $undoToken);

        $this->assertTrue($result->undone());
        $this->assertSame('attacker@example.test', $result->oldEmail);
        $this->assertSame('alice@example.test', $result->newEmail);
        $alice = $this->alice->fresh();
        $this->assertSame('alice@example.test', $alice->email);
        $this->assertNotNull($alice->email_verified_at);
        $this->assertFalse(Hash::check('alice-password', $alice->password));
        $this->assertNotSame('alice-old-token', $alice->remember_token);
        $this->assertSame(['bob-phone'], DB::table('sessions')->pluck('id')->all());
        $this->assertSame(0, DB::table('password_reset_tokens')->count());
        $this->assertSame(EmailChangeStatus::Cancelled, $this->confirm($pending));
        $this->assertSame([['id' => $this->alice->id, 'snapshot' => ['linked' => ['google:'.$this->alice->id]], 'inTransaction' => true]], RecordingLockdown::$lockdowns);
        Event::assertDispatched(EmailChangeUndone::class, fn (EmailChangeUndone $event): bool => $event->changeIds === [$id]
            && $event->restoredEmail === 'alice@example.test'
            && $event->replacedEmail === 'attacker@example.test');
        $this->assertSame(EmailChangeStatus::Used, $this->changes->undo($id, $undoToken)->status);
    }

    public function test_その後の変更もたどって戻す(): void
    {
        [$firstId, $firstUndo] = $this->changeAliceTo('b@example.test');
        [$secondId, $secondUndo] = $this->changeAliceTo('c@example.test');

        $result = $this->changes->undo($firstId, $firstUndo);

        $this->assertTrue($result->undone());
        $this->assertSame('alice@example.test', $this->aliceEmail());
        $this->assertSame(2, DB::table(EmailChanges::TABLE)->whereIn('id', [$firstId, $secondId])->whereNotNull('undone_at')->count());
        $this->assertSame(EmailChangeStatus::Used, $this->changes->undo($secondId, $secondUndo)->status);
    }

    public function test_後の変更だけを戻すと_前の変更はまだ戻せる(): void
    {
        [$firstId, $firstUndo] = $this->changeAliceTo('b@example.test');
        [$secondId, $secondUndo] = $this->changeAliceTo('c@example.test');

        $this->assertTrue($this->changes->undo($secondId, $secondUndo)->undone());
        $this->assertSame('b@example.test', $this->aliceEmail());

        $this->assertTrue($this->changes->undo($firstId, $firstUndo)->undone());
        $this->assertSame('alice@example.test', $this->aliceEmail());
    }

    public function test_モデルがアドレスを書き換えて保存しても_保存した値を残し_戻せる(): void
    {
        Event::fake([EmailChangeConfirmed::class]);
        $carol = LowercaseEmailChangeUser::create(['email' => 'carol@example.test', 'password' => Hash::make('carol-password')]);
        $requested = $this->changes->request($carol, 'Carol.New@Example.TEST');

        $confirmed = $this->changes->confirm($requested->id, $requested->token, $carol->fresh());

        $this->assertTrue($confirmed->confirmed());
        $this->assertSame('carol.new@example.test', $confirmed->newEmail);
        $this->assertSame('carol.new@example.test', DB::table(EmailChanges::TABLE)->where('id', $requested->id)->value('new_email'));
        Event::assertDispatched(EmailChangeConfirmed::class, fn (EmailChangeConfirmed $event): bool => $event->newEmail === 'carol.new@example.test');

        // 次の変更もそろえて保存される。たどりが合い、最初の変更から戻せる
        $second = $this->changes->request($carol->fresh(), 'CAROL.THIRD@example.test');
        $this->assertTrue($this->changes->confirm($second->id, $second->token, $carol->fresh())->confirmed());

        $undone = $this->changes->undo($requested->id, $confirmed->undoToken);

        $this->assertSame(EmailChangeStatus::Undone, $undone->status);
        $this->assertSame('carol.third@example.test', $undone->oldEmail);
        $this->assertSame('carol@example.test', $undone->newEmail);
        $this->assertSame('carol@example.test', (string) $carol->fresh()->email);
    }

    public function test_パッケージの外でアドレスを変えると_つながりが切れて_changed(): void
    {
        [$id, $undoToken] = $this->changeAliceTo('b@example.test');
        $this->alice->fresh()->forceFill(['email' => 'admin-set@example.test'])->save();

        $this->assertSame(EmailChangeStatus::Changed, $this->changes->undo($id, $undoToken)->status);
        $this->assertSame('admin-set@example.test', $this->aliceEmail());
    }

    public function test_戻す期限ちょうどから_expired_違うトークンは_invalid(): void
    {
        [$id, $undoToken] = $this->changeAliceTo('b@example.test');

        $this->assertSame(EmailChangeStatus::Invalid, $this->changes->undo($id, 'ecu_'.str_repeat('A', 40))->status);
        $this->assertSame(EmailChangeStatus::Invalid, $this->changes->undo($id, str_replace('ecu_', 'ec_', (string) $undoToken))->status);

        $this->travel(7)->days();
        $this->assertSame(EmailChangeStatus::Expired, $this->changes->undo($id, $undoToken)->status);
        $this->assertSame('b@example.test', $this->aliceEmail());
    }

    public function test_戻すアドレスがほかのアカウントに使われていれば_taken(): void
    {
        [$id, $undoToken] = $this->changeAliceTo('b@example.test');
        // 押さえを通らずに入った古いアカウント
        EmailChangeUser::create(['email' => 'alice@example.test', 'password' => 'x']);

        $this->assertSame(EmailChangeStatus::Taken, $this->changes->undo($id, $undoToken)->status);
        $this->assertSame('b@example.test', $this->aliceEmail());
    }

    public function test_差し込みが投げたら全部巻き戻る(): void
    {
        [$id, $undoToken] = $this->changeAliceTo('attacker@example.test');
        DB::table('sessions')->insert(['id' => 'attacker-laptop', 'user_id' => $this->alice->id, 'payload' => '', 'last_activity' => time()]);
        $passwordBefore = $this->alice->fresh()->password;
        RecordingLockdown::$throws = true;

        try {
            $this->changes->undo($id, $undoToken);
            $this->fail('投げなかった');
        } catch (RuntimeException $e) {
            $this->assertSame('連携を消せなかった', $e->getMessage());
        }

        $alice = $this->alice->fresh();
        $this->assertSame('attacker@example.test', $alice->email);
        $this->assertSame($passwordBefore, $alice->password);
        $this->assertSame(['attacker-laptop'], DB::table('sessions')->pluck('id')->all());
        $this->assertNull(DB::table(EmailChanges::TABLE)->where('id', $id)->value('undone_at'));

        RecordingLockdown::$throws = false;
        $this->assertTrue($this->changes->undo($id, $undoToken)->undone());
    }

    // 押さえ

    public function test_戻せる間は古いアドレスがほかの人には押さえられ_本人には押さえられない(): void
    {
        $this->changeAliceTo('b@example.test');

        $this->assertTrue($this->changes->isHeld('alice@example.test'));
        $this->assertTrue($this->changes->isHeld('alice@example.test', $this->bob));
        $this->assertFalse($this->changes->isHeld('alice@example.test', $this->alice));
        // EmailKey：大文字・全角・前後の空白も同じアドレス
        $this->assertTrue($this->changes->isHeld(' ＡＬＩＣＥ@example.test '));
        $this->assertFalse($this->changes->isHeld('b@example.test'));

        $this->travel(7)->days();
        $this->assertFalse($this->changes->isHeld('alice@example.test'));
    }

    public function test_戻すと押さえが外れる(): void
    {
        [$id, $undoToken] = $this->changeAliceTo('b@example.test');
        $this->changes->undo($id, $undoToken);

        $this->assertFalse($this->changes->isHeld('alice@example.test'));
    }

    public function test_NotHeldEmail_は押さえられたアドレスを断り_理由を出さない(): void
    {
        $this->changeAliceTo('b@example.test');
        app('translator')->setLocale('ja');

        $registration = Validator::make(['email' => 'Alice@example.test'], ['email' => [new NotHeldEmail]]);
        $this->assertTrue($registration->fails());
        $this->assertSame('emailは、今は使えません。別のメールアドレスを入れてください。', $registration->errors()->first('email'));

        $this->assertTrue(Validator::make(['email' => 'alice@example.test'], ['email' => [new NotHeldEmail($this->alice)]])->passes());
        $this->assertTrue(Validator::make(['email' => 'free@example.test'], ['email' => [new NotHeldEmail]])->passes());
        $this->assertTrue(Validator::make(['email' => ['x']], ['email' => [new NotHeldEmail]])->passes());
    }

    public function test_設定の書き違いは止める(): void
    {
        config(['foundation.email_changes.lockdown' => \stdClass::class]);
        $requested = $this->changes->request($this->alice, 'alice.new@example.test');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('foundation.email_changes.lockdown');
        $this->confirm($requested);
    }
}

/** 空きの判定をいつも「空いている」とする差し込み（一意の索引の違反を起こすため） */
class NeverTakenAvailability implements EmailAvailability
{
    public function isTaken(string $email, \Illuminate\Database\Eloquent\Model $except): bool
    {
        return false;
    }
}
