<?php

namespace Miraqceed\Foundation\MachineTokens;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Miraqceed\Foundation\Database\LockErrors;
use Miraqceed\Foundation\OneTimeCodes\CodeFormat;
use RuntimeException;

/**
 * 機械が繰り返し使う鍵と、止めずに入れ替えること。呼んだときだけ働く。
 *
 * - 対象ごとに1行で、今・入れ替え待ち・前の鍵を SHA-256 だけで持つ。平文は戻り値にだけ入れる
 * - 入れ替えは、今の鍵で rotate() → 入れ替え待ちに置く → 入れ替え待ちが初めて使われたら切り替え、前の鍵を猶予の間だけ残す。
 *   入れ替えの答えが届かなくても今の鍵は効くので、機械は締め出されない
 * - 書き換えはどれも、読んだ値を条件と書く値に入れた UPDATE 1つ（列から列へ写さない。MySQL は代入を左から順に評価する）。
 *   読んだ後にほかの要求が書いていれば、条件が合わずに 0 行になる
 * - 形が違えば DB を引かない。確かめ・入れ替えは、どの理由で断っても null
 * - 時刻は UTC で書いて UTC で比べる。表はアプリの既定の接続に置く
 */
final class MachineTokens
{
    public const TABLE = 'foundation_machine_tokens';

    /** 確かめ・入れ替えのトランザクションを試す回数（行き詰まり・直列化の失敗で試し直す） */
    private const ATTEMPTS = 3;

    /** issue() を試す回数（同じ対象への同時の issue() の一意の索引の違反・行き詰まりで試し直す） */
    private const ISSUE_ATTEMPTS = 5;

    /** 最後に使った時刻を書く間（秒）。機械の要求のたびに書き込みを増やさない */
    private const LAST_USED_INTERVAL = 60;

    /** 表に書く時刻の形（UTC） */
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * 新しい鍵を出す。その対象の今・入れ替え待ち・前の鍵は、その場で置き換わる（猶予を置かない）。
     * 同じ対象に同時に出したら、後に確定したものだけが効く。
     *
     * @param  string  $subject  対象（1〜255字の UTF-8。例 "server:7"）
     *
     * @throws InvalidArgumentException 対象が受けられない形のとき（文に値は入れない）
     */
    public function issue(MachineKind $kind, string $subject): IssuedToken
    {
        self::assertSubject($subject);

        $token = CodeFormat::generate($kind->prefix);
        $hash = CodeFormat::hash($token);

        for ($attempt = 1; ; $attempt++) {
            try {
                if ($this->replace($kind, $subject, $hash, locking: $attempt > 1)) {
                    return new IssuedToken($token, $kind->name, $subject);
                }
            } catch (QueryException $e) {
                // 同じ対象に同時に INSERT した（一意の索引の違反）か、行き詰まり・直列化の失敗。
                // アプリのトランザクションの中の行き詰まりは、外側ごと巻き戻っているので試し直さない
                $retryable = $e instanceof UniqueConstraintViolationException
                    || (DB::transactionLevel() === 0 && LockErrors::isDeadlock($e));
                if (! $retryable || $attempt >= self::ISSUE_ATTEMPTS) {
                    throw $e;
                }
            }
            if ($attempt >= self::ISSUE_ATTEMPTS) {
                throw new RuntimeException('機械用のトークンを出せませんでした（同じ対象への同時の書き込みが続いた）');
            }
        }
    }

    /**
     * 対象の行を新しい鍵で置き換える。なければ INSERT。置き換えられなかった（読んだ後に消えた）ら false。
     *
     * 行はロックを取らない読みで引いてから id で書く。対象の条件で書くと、MySQL は合う行が無い所の索引の隙間をロックし、
     * 別々の対象への同時の INSERT どうしが互いを待って行き詰まる（OneTimeCodes と同じ）。
     * 試し直し（$locking）では、MySQL の REPEATABLE READ の古い読みを避けるため、最新の行を読む読み方にする
     */
    private function replace(MachineKind $kind, string $subject, string $hash, bool $locking): bool
    {
        $now = self::now()->format(self::FORMAT);

        $id = DB::table(self::TABLE)
            ->where('kind', $kind->name)
            ->where('subject', $subject)
            ->when($locking, fn (Builder $query) => $query->lockForUpdate())
            ->value('id');

        if ($id !== null) {
            return DB::table(self::TABLE)->where('id', $id)->update([
                'current_hash' => $hash,
                'current_issued_at' => $now,
                'pending_hash' => null,
                'pending_issued_at' => null,
                'previous_hash' => null,
                'previous_until' => null,
                'last_used_at' => null,
            ]) === 1;
        }

        // 一意の索引の違反で、呼び手のトランザクション（PostgreSQL）を壊さないよう、セーブポイント（外ならトランザクション）で包む
        DB::transaction(fn () => DB::table(self::TABLE)->insert([
            'kind' => $kind->name,
            'subject' => $subject,
            'current_hash' => $hash,
            'current_issued_at' => $now,
            'created_at' => $now,
        ]));

        return true;
    }

    /**
     * 鍵を確かめる。認めなければ、理由を問わず null（形が違う・無い・別の種類・前の鍵の猶予切れ・入れ替え待ちの期限切れ・
     * 失効・出し直された）。入れ替え待ちの鍵が初めて使われたら、ここで切り替える。
     *
     * @param  mixed  $value  送られた値（文字列でなければ断る）
     */
    public function authenticate(MachineKind $kind, #[\SensitiveParameter] mixed $value): ?AuthenticatedMachine
    {
        // 形が違えば DB を引かない
        if (! CodeFormat::matches($kind->prefix, $value)) {
            return null;
        }
        $hash = CodeFormat::hash($value);

        // 行き詰まり・直列化の失敗（PostgreSQL の REPEATABLE READ・SERIALIZABLE で、先に書かれたとき）は試し直す
        return DB::transaction(function () use ($kind, $hash): ?AuthenticatedMachine {
            $now = self::now();

            // 1回目はロックを取らずに読む。切り替えが 0 行なら、最新の行を読み直して判断する（並んだ切り替えが先に済んだなど）
            for ($read = 0; $read < 2; $read++) {
                $row = self::find($kind, $hash, locking: $read > 0);
                if ($row === null) {
                    return null;
                }

                if (hash_equals((string) $row->current_hash, $hash)) {
                    $this->touch($row, $now);

                    return new AuthenticatedMachine($kind->name, (string) $row->subject, self::shouldRotate($kind, $row, $now), false);
                }

                if ($row->previous_hash !== null && hash_equals((string) $row->previous_hash, $hash)) {
                    if ($row->previous_until === null || self::fromStored((string) $row->previous_until) <= $now) {
                        return null;
                    }
                    $this->touch($row, $now);

                    return new AuthenticatedMachine($kind->name, (string) $row->subject, false, true);
                }

                if ($row->pending_hash === null || ! hash_equals((string) $row->pending_hash, $hash)) {
                    return null;
                }
                if (self::pendingExpired($kind, $row, $now)) {
                    return null;
                }

                if ($this->promote($kind, $row, $hash, $now)) {
                    return new AuthenticatedMachine($kind->name, (string) $row->subject, false, false);
                }
            }

            return null;
        }, self::ATTEMPTS);
    }

    /**
     * 今の鍵のときだけ、新しい鍵を作って入れ替え待ちに置き、平文を返す（前の入れ替え待ちは置き換わる）。
     * 今の鍵は、入れ替え待ちが使われるまで効いたまま。前の鍵・入れ替え待ちの鍵・そのほかでは null。
     *
     * @param  mixed  $value  送られた値（文字列でなければ断る）
     */
    public function rotate(MachineKind $kind, #[\SensitiveParameter] mixed $value): ?IssuedToken
    {
        if (! CodeFormat::matches($kind->prefix, $value)) {
            return null;
        }
        $hash = CodeFormat::hash($value);

        return DB::transaction(function () use ($kind, $hash): ?IssuedToken {
            $now = self::now();
            $row = DB::table(self::TABLE)
                ->where('kind', $kind->name)
                ->where('current_hash', $hash)
                ->first(['id', 'subject']);
            if ($row === null) {
                return null;
            }

            $token = CodeFormat::generate($kind->prefix);
            // 今の鍵のままのときだけ書く（読んだ後に切り替え・出し直し・失効があれば 0 行）
            $updated = DB::table(self::TABLE)
                ->where('id', $row->id)
                ->where('current_hash', $hash)
                ->update([
                    'pending_hash' => CodeFormat::hash($token),
                    'pending_issued_at' => $now->format(self::FORMAT),
                    'last_used_at' => $now->format(self::FORMAT),
                ]);

            return $updated === 1 ? new IssuedToken($token, $kind->name, (string) $row->subject) : null;
        }, self::ATTEMPTS);
    }

    /** 対象の鍵をすべて、その場で効かなくする（行を消す）。消したら true */
    public function revoke(MachineKind $kind, string $subject): bool
    {
        return DB::table(self::TABLE)
            ->where('kind', $kind->name)
            ->where('subject', $subject)
            ->delete() > 0;
    }

    private static function find(MachineKind $kind, string $hash, bool $locking): ?object
    {
        return DB::table(self::TABLE)
            ->where('kind', $kind->name)
            ->where(fn (Builder $query) => $query
                ->where('current_hash', $hash)
                ->orWhere('pending_hash', $hash)
                ->orWhere('previous_hash', $hash))
            // MySQL の REPEATABLE READ では、ロックを取らない読みはトランザクションの初めの写しを読む。読み直しは最新の行を読む
            ->when($locking, fn (Builder $query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * 入れ替え待ちを今にし、今を前にする。読んだ今の鍵と入れ替え待ちのままのときだけ書く
     * （読んだ後に入れ替え・出し直し・失効・ほかの切り替えがあれば 0 行で false）
     */
    private function promote(MachineKind $kind, object $row, string $hash, CarbonImmutable $now): bool
    {
        $at = $now->format(self::FORMAT);

        return DB::table(self::TABLE)
            ->where('id', $row->id)
            ->where('current_hash', (string) $row->current_hash)
            ->where('pending_hash', $hash)
            ->update([
                'previous_hash' => (string) $row->current_hash,
                'previous_until' => $now->addSeconds($kind->graceSeconds)->format(self::FORMAT),
                'current_hash' => $hash,
                'current_issued_at' => $at,
                'pending_hash' => null,
                'pending_issued_at' => null,
                'last_used_at' => $at,
            ]) === 1;
    }

    /** 最後に使った時刻を、前に書いてから LAST_USED_INTERVAL 秒を過ぎていれば書く */
    private function touch(object $row, CarbonImmutable $now): void
    {
        $threshold = $now->subSeconds(self::LAST_USED_INTERVAL);
        if ($row->last_used_at !== null && self::fromStored((string) $row->last_used_at) > $threshold) {
            return;
        }

        DB::table(self::TABLE)
            ->where('id', $row->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('last_used_at')
                ->orWhere('last_used_at', '<=', $threshold->format(self::FORMAT)))
            ->update(['last_used_at' => $now->format(self::FORMAT)]);
    }

    private static function shouldRotate(MachineKind $kind, object $row, CarbonImmutable $now): bool
    {
        if ($kind->rotateAfterSeconds === null) {
            return false;
        }
        if (self::fromStored((string) $row->current_issued_at)->addSeconds($kind->rotateAfterSeconds) > $now) {
            return false;
        }

        // 期限の内の入れ替え待ちがあれば促さない（並んだ要求が続けざまに入れ替え、互いの入れ替え待ちを消し合わない）
        return $row->pending_hash === null || self::pendingExpired($kind, $row, $now);
    }

    private static function pendingExpired(MachineKind $kind, object $row, CarbonImmutable $now): bool
    {
        return $row->pending_issued_at === null
            || self::fromStored((string) $row->pending_issued_at)->addSeconds($kind->pendingSeconds) <= $now;
    }

    private static function assertSubject(string $subject): void
    {
        if ($subject === '' || ! mb_check_encoding($subject, 'UTF-8') || mb_strlen($subject, 'UTF-8') > 255) {
            throw new InvalidArgumentException('機械用のトークンの対象は、1〜255字の UTF-8 の文字列にしてください');
        }
    }

    /** 今（UTC、秒で切り捨て） */
    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }

    private static function fromStored(string $value): CarbonImmutable
    {
        // PostgreSQL・MySQL・SQLite のどれも 'Y-m-d H:i:s' で返す（秒より下を持たない列）
        return CarbonImmutable::parse(substr($value, 0, 19), 'UTC');
    }
}
