<?php

namespace Miraqceed\Foundation\Login;

/**
 * 数える枠1つ（鍵・上限・窓の秒数）。LoginLock の中だけで使う。
 *
 * @internal
 */
final class Bucket
{
    public function __construct(
        public readonly string $key,
        public readonly int $max,
        public readonly int $decay,
    ) {}
}
