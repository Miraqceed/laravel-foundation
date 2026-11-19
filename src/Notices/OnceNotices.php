<?php

namespace Miraqceed\Foundation\Notices;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Miraqceed\Foundation\Failures\FailureReporter;
use RuntimeException;
use Throwable;

/**
 * 条件に達した知らせを、鍵ごとに1回だけ送る。呼んだときだけ働く。
 *
 * - 取る：鍵の行を insertOrIgnore で入れた処理だけが送る。送っていない行が取り直しの分より古ければ、条件つきの UPDATE で取り直す
 *   （取った後にプロセスが止まると、送られないまま残るため）
 * - 送れなければ自分の token の印だけを戻す（次の確かめで送り直す）。keepOnFailure なら送ったにして、二度送らない
 * - トランザクションの中で呼ばれたら、取るのも送るのも確定の後（中で一意の索引に入れると、MySQL ではその錠を確定まで持ち、
 *   同じ鍵を確かめるほかの要求が待つ）
 * - 表は既定の接続に置く。時刻は UTC で書いて UTC で比べる
 * - 鍵に個人の情報（メールアドレス・名前）を入れない。鍵は失敗の記録に入る（id と回で作る）
 */
final class OnceNotices
{
    public const TABLE = 'foundation_notices';

    /** 表に書く時刻の形（UTC） */
    private const FORMAT = 'Y-m-d H:i:s';

    public function __construct(private readonly FailureReporter $reporter) {}

    /**
     * 鍵の知らせを1回だけ送る。渡した関数が投げても投げない（Failed を返し、記録に残す）。
     *
     * @param  callable(): mixed  $send
     * @param  ?DateTimeInterface  $keepUntil  印を残す期限。渡さなければ、取ってから foundation.notices.keep_days 日
     * @param  bool  $keepOnFailure  送れなくても印を戻さない（受け取った後に失敗を返しうる送り先へ二度送らない）
     * @param  ?int  $claimMinutes  取ったまま送っていない印を取り直すまでの分。渡さなければ foundation.notices.claim_minutes
     *
     * @throws InvalidArgumentException 鍵が空・255字を超える・壊れた UTF-8 のとき
     */
    public function send(string $key, callable $send, ?DateTimeInterface $keepUntil = null, bool $keepOnFailure = false, ?int $claimMinutes = null): NoticeResult
    {
        self::checkKey($key);

        $result = null;
        $inline = true;

        // トランザクションの外なら、すぐに呼ばれる。中なら確定の後に呼ばれ（巻き戻れば呼ばれない）、ここでは Deferred を返す
        $this->db()->afterCommit(function () use (&$result, &$inline, $key, $send, $keepUntil, $keepOnFailure, $claimMinutes): void {
            if ($inline) {
                $result = $this->sendNow($key, $send, $keepUntil, $keepOnFailure, $claimMinutes);

                return;
            }

            // 確定の後に投げると、確定した呼び手の処理が失敗に見える。記録だけ残す
            try {
                $this->sendNow($key, $send, $keepUntil, $keepOnFailure, $claimMinutes);
            } catch (Throwable $e) {
                $this->reporter->report($e);
            }
        });
        $inline = false;

        return $result ?? NoticeResult::Deferred;
    }

    /**
     * キューで送るときに、先に印を取る。取れなければ null（送った・ほかの処理が送っているところ）。
     * トランザクションの中で呼ぶと、印はそのトランザクションで書かれる（巻き戻れば取らなかったことになる。確定までほかの要求が同じ鍵で待つ）
     *
     * @throws InvalidArgumentException 鍵が空・255字を超える・壊れた UTF-8 のとき
     */
    public function claim(string $key, ?DateTimeInterface $keepUntil = null, ?int $claimMinutes = null): ?NoticeClaim
    {
        self::checkKey($key);

        $token = $this->take($key, $keepUntil, $claimMinutes);

        return $token === null ? null : new NoticeClaim($key, $token);
    }

    /** 送る直前に：この token がまだ印を持ち、送っていなければ、取った時刻を今に延ばして真 */
    public function holds(NoticeClaim $claim): bool
    {
        $mine = $this->mine($claim->key, $claim->token);

        if ((clone $mine)->update(['claimed_at' => self::now()->format(self::FORMAT)]) === 1) {
            return true;
        }

        // MySQL は値の変わらない行を数えない（同じ秒に取って確かめると 0）。印がまだ自分のものかを読み直す。
        // 取った時刻が今なので、この間に取り直されることはない
        return (clone $mine)->exists();
    }

    /** 送ったにする（この token が印を持ち、送っていないときだけ） */
    public function sent(NoticeClaim $claim): bool
    {
        return $this->markSent($claim->key, $claim->token);
    }

    /** 印を戻す（この token が印を持ち、送っていないときだけ）。次の確かめで送り直す */
    public function release(NoticeClaim $claim): bool
    {
        return $this->mine($claim->key, $claim->token)->delete() === 1;
    }

    /** 条件が戻ったときに印を消す。次に条件に達したら、また送る */
    public function forget(string $key): void
    {
        self::checkKey($key);

        $this->table()->where('key_hash', self::hash($key))->delete();
    }

    /** 印があるか（送った・取って送っているところ） */
    public function has(string $key): bool
    {
        self::checkKey($key);

        return $this->table()->where('key_hash', self::hash($key))->exists();
    }

    /** @param  callable(): mixed  $send */
    private function sendNow(string $key, callable $send, ?DateTimeInterface $keepUntil, bool $keepOnFailure, ?int $claimMinutes): NoticeResult
    {
        $token = $this->take($key, $keepUntil, $claimMinutes);
        if ($token === null) {
            return NoticeResult::AlreadySent;
        }

        try {
            $send();
        } catch (Throwable $e) {
            $this->failed($key, $token, $e, $keepOnFailure);

            return NoticeResult::Failed;
        }

        // 送るのが取り直しの分より長く掛かり、ほかの処理に取り直されていれば、印は書けない（送ったことは変わらない）
        $this->markSent($key, $token);

        return NoticeResult::Sent;
    }

    /** 印を取る。取れたら token、取れなければ null */
    private function take(string $key, ?DateTimeInterface $keepUntil, ?int $claimMinutes): ?string
    {
        $now = self::now();
        $token = bin2hex(random_bytes(16));
        $keep = $keepUntil !== null
            ? CarbonImmutable::instance($keepUntil)->utc()
            : $now->addDays(max(1, (int) config('foundation.notices.keep_days', 400)));

        $inserted = $this->table()->insertOrIgnore([
            'key' => $key,
            'key_hash' => self::hash($key),
            'token' => $token,
            'claimed_at' => $now->format(self::FORMAT),
            'sent_at' => null,
            'keep_until' => $keep->format(self::FORMAT),
        ]);
        if ($inserted === 1) {
            return $token;
        }

        // 取ったまま止まった印を取り直す。同時に取り直しても、条件つきの UPDATE なので1つだけが通る
        $minutes = max(1, $claimMinutes ?? (int) config('foundation.notices.claim_minutes', 15));
        $retaken = $this->table()
            ->where('key_hash', self::hash($key))
            ->whereNull('sent_at')
            ->where('claimed_at', '<=', $now->subMinutes($minutes)->format(self::FORMAT))
            ->update([
                'token' => $token,
                'claimed_at' => $now->format(self::FORMAT),
                'keep_until' => $keep->format(self::FORMAT),
            ]);

        return $retaken === 1 ? $token : null;
    }

    private function markSent(string $key, string $token): bool
    {
        return $this->mine($key, $token)->update(['sent_at' => self::now()->format(self::FORMAT)]) === 1;
    }

    /** 送れなかった：印を戻し（keepOnFailure なら送ったにする）、記録に残す。投げない */
    private function failed(string $key, string $token, Throwable $e, bool $keepOnFailure): void
    {
        try {
            // 残すだけでは、取り直しの分の後に取り直されて送り直す。送ったにして、二度送らない
            $keepOnFailure ? $this->markSent($key, $token) : $this->mine($key, $token)->delete();
            $what = $keepOnFailure ? '印は残しました（送り直しません）' : '印を戻しました（次の確かめで送り直します）';
        } catch (Throwable $markFailure) {
            $this->reporter->report($markFailure);
            $what = '印を書き換えられませんでした（取り直しの分の後に送り直します）';
        }

        // 関数の例外の文は入れない（送り先・メールアドレスが入りうる）。種類の名前だけ
        $this->reporter->report(new RuntimeException(sprintf('知らせ「%s」を送れませんでした（%s）。%s', $key, $e::class, $what)));
    }

    /** この token が持つ、送っていない印 */
    private function mine(string $key, string $token): Builder
    {
        return $this->table()
            ->where('key_hash', self::hash($key))
            ->where('token', $token)
            ->whereNull('sent_at');
    }

    private static function checkKey(string $key): void
    {
        if ($key === '' || ! mb_check_encoding($key, 'UTF-8') || mb_strlen($key, 'UTF-8') > 255) {
            throw new InvalidArgumentException('知らせの鍵は、255字までの空でない UTF-8 の文字列にしてください');
        }
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    private function db(): Connection
    {
        return DB::connection();
    }

    private function table(): Builder
    {
        return $this->db()->table(self::TABLE);
    }

    private static function now(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfSecond();
    }
}
