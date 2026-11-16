<?php

namespace Miraqceed\Foundation\Attempts;

/**
 * 続けた失敗の枠（確認コードなど）。失敗を時間で消えない数えで数え、上限に達したらロックする
 */
final readonly class ConsecutiveLimit
{
    /**
     * @param  string  $key  数えの鍵（AttemptKey::for() で作る）
     * @param  int  $max  続けた失敗の上限。上限に届く試みからロックする
     * @param  int  $lockSeconds  ロックの秒数。明けた後は1回だけ照合し、失敗ならまたロックする
     */
    public function __construct(
        public string $key,
        public int $max,
        public int $lockSeconds,
    ) {}

    /** 上限とロックの秒数を設定（foundation.attempts.consecutive）から取る */
    public static function fromConfig(string $key): self
    {
        return new self(
            $key,
            (int) config('foundation.attempts.consecutive.max'),
            (int) config('foundation.attempts.consecutive.lock_seconds'),
        );
    }
}
