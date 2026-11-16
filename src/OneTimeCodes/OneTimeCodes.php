<?php

namespace Miraqceed\Foundation\OneTimeCodes;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;

/**
 * 1回だけ使える合言葉（期限つき・ハッシュで持つ）。呼んだときだけ働く。
 *
 * - 出す：平文は戻り値にだけ入れ、表には SHA-256 だけを書く。同じ種類・同じ対象のまだ使っていないものは、同じトランザクションで失効させる
 * - 受け取る：期限・使った・失効・種類を条件に入れた UPDATE 1つで、同時の要求でも1回だけ通す。受け取れなければ、理由を問わず null
 * - 表はアプリの既定の接続に置く。受け取った後の処理（$then）の書き込みも同じ接続で、同じトランザクションに入る
 * - 時刻は UTC で書いて UTC で比べる（アプリや DB の接続の時間帯に左右されない）
 * - 失敗の数えは持たない。受け取りの口は Attempts で包む
 */
final class OneTimeCodes
{
    public const TABLE = 'foundation_one_time_codes';

    /** 表に書く時刻の形（UTC） */
    private const FORMAT = 'Y-m-d H:i:s';

    /**
     * 合言葉を作って書く。
     *
     * @param  ?string  $subject  対象（255字までの UTF-8。例：利用者の id）。null でなければ、同じ種類・同じ対象の前の合言葉を失効させる
     * @param  array<array-key, mixed>  $data  受け取ったときに返すもの（JSON にできるもの。秘密を入れない）
     *
     * @throws InvalidArgumentException 対象・添えるものが受けられない形のとき（文に値は入れない）
     */
    public function issue(CodeKind $kind, ?string $subject = null, array $data = []): IssuedCode
    {
        if ($subject !== null && (! mb_check_encoding($subject, 'UTF-8') || mb_strlen($subject, 'UTF-8') > 255)) {
            throw new InvalidArgumentException('合言葉の対象は、255字までの UTF-8 の文字列にしてください');
        }
        try {
            $json = $data === [] ? null : json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $e) {
            throw new InvalidArgumentException('合言葉に添えるものを JSON にできません（壊れた UTF-8・NAN など）', 0, $e);
        }

        $now = self::now();
        $expiresAt = $now->addSeconds($kind->ttlSeconds);
        $code = CodeFormat::generate($kind->prefix);

        DB::transaction(function () use ($kind, $subject, $json, $now, $expiresAt, $code): void {
            if ($subject !== null) {
                $this->revokeLive($kind, $subject, $now);
            }
            DB::table(self::TABLE)->insert([
                'kind' => $kind->name,
                'code_hash' => CodeFormat::hash($code),
                'subject' => $subject,
                'data' => $json,
                'expires_at' => $expiresAt->format(self::FORMAT),
                'created_at' => $now->format(self::FORMAT),
            ]);
        });

        return new IssuedCode($code, self::toAppTimezone($expiresAt));
    }

    /**
     * 1回だけ受け取る。形が違う・無い・別の種類・切れた（期限ちょうども）・使った・失効した・同時の要求に競り負けた、のどれでも null。
     *
     * $then を渡せば、使った印を書いたのと同じトランザクションの中で呼び、戻り値を ClaimedCode::$result に入れる。
     * $then が投げたら巻き戻し（合言葉はまた受け取れる）、例外はそのまま出す。
     *
     * @param  mixed  $code  送られた値（文字列でなければ断る）
     * @param  ?Closure(ClaimedCode): mixed  $then
     */
    public function claim(CodeKind $kind, mixed $code, ?Closure $then = null): ?ClaimedCode
    {
        // 形が違えば DB を引かない
        if (! CodeFormat::matches($kind->prefix, $code)) {
            return null;
        }
        $hash = CodeFormat::hash($code);

        return DB::transaction(function () use ($kind, $hash, $then): ?ClaimedCode {
            $now = self::now()->format(self::FORMAT);

            // 条件つきの UPDATE 1つで判断する（読んでから判断すると、同じ合言葉が同時に2回通る）
            $updated = DB::table(self::TABLE)
                ->where('code_hash', $hash)
                ->where('kind', $kind->name)
                ->whereNull('claimed_at')
                ->whereNull('revoked_at')
                ->where('expires_at', '>', $now)
                ->update(['claimed_at' => $now]);
            if ($updated !== 1) {
                return null;
            }

            $row = DB::table(self::TABLE)->where('code_hash', $hash)->first(['kind', 'subject', 'data', 'created_at']);
            $claimed = new ClaimedCode(
                (string) $row->kind,
                $row->subject === null ? null : (string) $row->subject,
                $row->data === null ? [] : (array) json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR),
                self::toAppTimezone(self::fromStored((string) $row->created_at)),
            );

            return $then === null ? $claimed : $claimed->withResult($then($claimed));
        });
    }

    /** 同じ種類・同じ対象の、まだ使っていない（効いている）合言葉を失効させ、その数を返す */
    public function revoke(CodeKind $kind, string $subject): int
    {
        return $this->revokeLive($kind, $subject, self::now());
    }

    private function revokeLive(CodeKind $kind, string $subject, CarbonImmutable $now): int
    {
        $at = $now->format(self::FORMAT);

        return DB::table(self::TABLE)
            ->where('kind', $kind->name)
            ->where('subject', $subject)
            ->whereNull('claimed_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', $at)
            ->update(['revoked_at' => $at]);
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

    private static function toAppTimezone(CarbonImmutable $utc): CarbonImmutable
    {
        return $utc->setTimezone((string) config('app.timezone', 'UTC'));
    }
}
