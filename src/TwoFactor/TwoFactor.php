<?php

namespace Miraqceed\Foundation\TwoFactor;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Miraqceed\Foundation\Attempts\AttemptKey;
use Miraqceed\Foundation\Attempts\Attempts;
use Miraqceed\Foundation\Attempts\ConsecutiveLimit;
use Miraqceed\Foundation\Attempts\TooManyAttempts;

/**
 * 手書きの認証のアプリ向けの二段階の認証（認証アプリの確認コード）。
 *
 * - 利用者の表の3つの列（秘密の鍵・確定の時刻・最後に受けた刻み）を、条件つきの UPDATE で書く。同時の要求でも1つだけが通る
 *   - 確かめ：最後に受けた刻みより後のときだけ書く（使い回し・同じコードの2つの要求）
 *   - 登録：まだ確定していないときだけ書き、登録に使ったコードの刻みも残す（登録に使ったコードをすぐのログインに使い回させない）
 * - 列はモデルの型に頼らずに読み書きする。秘密の鍵は Crypt::encryptString の形で書く（モデルに encrypted の型があっても同じ形）
 * - 間違い（形の違う入力も）は、試みの数えの「続けた失敗」でアカウントごとに数える。ロック中はコードを確かめもしない
 */
final class TwoFactor
{
    public function __construct(private readonly Attempts $attempts) {}

    /** 認証アプリに登録する URI。issuer は foundation.two_factor.issuer（null なら app.name） */
    public function uri(string $account, string $secret): string
    {
        $issuer = config('foundation.two_factor.issuer') ?? config('app.name');

        return Totp::uri((string) $issuer, $account, $secret);
    }

    public function isConfirmed(Model $user): bool
    {
        return $this->row($user)?->{$this->column('confirmed_at')} !== null;
    }

    /**
     * 登録の確かめ。打たれたコードが新しい鍵に合い、まだ確定していなければ、鍵（暗号にして）・確定の時刻・使った刻みを書く
     *
     * @throws TooManyAttempts ロック中（コードは確かめない）
     */
    public function confirm(Model $user, string $secret, string $code): bool
    {
        // 短すぎる鍵（空・80 ビット未満）は、作る側の誤り。数えずに止める
        if (strlen(Totp::base32Decode($secret)) < self::MIN_SECRET_BYTES) {
            throw new \InvalidArgumentException('秘密の鍵が短すぎます（80 ビット以上。Totp::generateSecret() で作ってください）');
        }

        return $this->guarded($user, function () use ($user, $secret, $code): bool {
            $step = Totp::verify($secret, $code, now()->getTimestamp(), null, $this->window());
            if ($step === null) {
                return false;
            }

            return $this->query($user)
                ->whereNull($this->column('confirmed_at'))
                ->update([
                    $this->column('secret') => Crypt::encryptString($secret),
                    $this->column('confirmed_at') => now(),
                    $this->column('last_step') => $step,
                ]) === 1;
        });
    }

    /**
     * ログインなどの確かめ。最後に受けた刻みより後のコードのときだけ、その刻みを書いて通す。
     * 登録していない利用者は、数えずに断る（確かめる鍵がない）
     *
     * @throws TooManyAttempts ロック中（コードは確かめない）
     */
    public function verify(Model $user, string $code): bool
    {
        $row = $this->row($user);
        if ($row === null || $row->{$this->column('confirmed_at')} === null || $row->{$this->column('secret')} === null) {
            return false;
        }
        $secret = Crypt::decryptString($row->{$this->column('secret')});

        return $this->guarded($user, function () use ($user, $secret, $code): bool {
            // 最後に受けた刻みは、読み直さずに確かめの条件に使う。並んだ要求は、条件つきの UPDATE で1つだけが通る
            $lastStep = $this->row($user)?->{$this->column('last_step')};
            $step = Totp::verify($secret, $code, now()->getTimestamp(), $lastStep === null ? null : (int) $lastStep, $this->window());
            if ($step === null) {
                return false;
            }

            $lastStepColumn = $this->column('last_step');

            return $this->query($user)
                ->where(fn (Builder $query) => $query->whereNull($lastStepColumn)->orWhere($lastStepColumn, '<', $step))
                ->update([$lastStepColumn => $step]) === 1;
        });
    }

    /** 3つの列を空にする（登録し直し・アカウントを外すとき。1か所で空にする） */
    public function reset(Model $user): void
    {
        $this->query($user)->update([
            $this->column('secret') => null,
            $this->column('confirmed_at') => null,
            $this->column('last_step') => null,
        ]);
        $this->refresh($user);
    }

    /**
     * 通った印を置く。セッションの番号を作り直してから、利用者と登録した鍵に結び付けた印を置く
     * （鍵を登録し直すと、前の鍵で通ったセッションはまた聞かれる）
     */
    public function pass(Request $request, Model $user): void
    {
        $request->session()->regenerate(true);
        $request->session()->put(self::SESSION_KEY, $this->mark($user));
    }

    /** このセッションが、今の鍵で通ったか */
    public function passed(Request $request, Model $user): bool
    {
        $mark = $this->mark($user);
        $stored = $request->session()->get(self::SESSION_KEY);

        return $mark !== null && is_string($stored) && hash_equals($mark, $stored);
    }

    private const SESSION_KEY = 'foundation.two_factor.passed';

    /** 受ける秘密の鍵の短さの下限（80 ビット） */
    private const MIN_SECRET_BYTES = 10;

    /** 利用者の id と登録した鍵の HMAC（APP_KEY）。登録していなければ null */
    private function mark(Model $user): ?string
    {
        $row = $this->row($user);
        if ($row === null || $row->{$this->column('confirmed_at')} === null || $row->{$this->column('secret')} === null) {
            return null;
        }

        return hash_hmac('sha256', $this->table($user)."\n".$user->getKey()."\n".Crypt::decryptString($row->{$this->column('secret')}), (string) config('app.key'));
    }

    /**
     * 照合を続けた失敗で数えて包む。失敗でロックになったら、出来事を出す
     *
     * @param  \Closure():bool  $verify
     */
    private function guarded(Model $user, \Closure $verify): bool
    {
        $limit = ConsecutiveLimit::fromConfig(AttemptKey::for('two-factor', $this->table($user), (string) $user->getKey()));

        $ok = $this->attempts->attemptConsecutive($limit, $verify);

        $eventKey = AttemptKey::for('two-factor-locked-event', $this->table($user), (string) $user->getKey());

        if ($ok) {
            // 通ると数えとロックが空になる。出来事の印も消し、次のロックでまた出来事を出す（並んだ失敗が先にロックしていたとき）
            Cache::forget($eventKey);
            $this->refresh($user);
        } elseif (($lockedFor = $this->attempts->lockedFor($limit)) > 0
            // 並んだ要求のどれもがロックを見る。出来事は、1 回のロックにつき、印を先に立てた要求だけが出す
            && Cache::add($eventKey, true, $lockedFor)) {
            event(new TwoFactorLocked($user));
        }

        return $ok;
    }

    private function query(Model $user): Builder
    {
        return DB::connection($user->getConnectionName())
            ->table($this->table($user))
            ->where($user->getKeyName(), $user->getKey());
    }

    /** 表はモデルの表（設定 foundation.two_factor.table で替えたときだけ、その表） */
    private function table(Model $user): string
    {
        return (string) (config('foundation.two_factor.table') ?? $user->getTable());
    }

    private function row(Model $user): ?object
    {
        return $this->query($user)->first([$this->column('secret'), $this->column('confirmed_at'), $this->column('last_step')]);
    }

    private function refresh(Model $user): void
    {
        if ($user->exists) {
            $user->refresh();
        }
    }

    private function column(string $name): string
    {
        return (string) config("foundation.two_factor.columns.{$name}");
    }

    private function window(): int
    {
        return (int) config('foundation.two_factor.window', 1);
    }
}
