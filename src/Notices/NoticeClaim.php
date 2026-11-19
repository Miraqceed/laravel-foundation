<?php

namespace Miraqceed\Foundation\Notices;

/**
 * OnceNotices::claim() で取った印。キューのジョブに渡せる（鍵と token だけ。直列化できる）。
 * ジョブは送る直前に holds() を確かめ、送れたら sent()、投げたら release() を呼ぶ。
 * どれも、この token がまだ印を持つときだけ効く（キューで待つ間に取り直されたら効かない）
 */
final class NoticeClaim
{
    public function __construct(
        public readonly string $key,
        public readonly string $token,
    ) {}

    /** まだ印を持ち、送っていなければ、取った時刻を今に延ばして真。偽なら送らない（取り直した側が送る） */
    public function holds(): bool
    {
        return app(OnceNotices::class)->holds($this);
    }

    /** 送ったにする。印を持っていなければ偽 */
    public function sent(): bool
    {
        return app(OnceNotices::class)->sent($this);
    }

    /** 印を戻す（次の確かめで送り直す）。印を持っていなければ偽 */
    public function release(): bool
    {
        return app(OnceNotices::class)->release($this);
    }
}
