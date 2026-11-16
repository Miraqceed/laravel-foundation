<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Cache\ArrayStore;

/**
 * 足し算・引き算の手前で、試しの関数を呼ぶ array のキャッシュ。並んだ要求の順を決めて起こすのに使う（Fiber で止めるなど）
 */
final class HookedArrayStore extends ArrayStore
{
    /** @var (\Closure(string, int): void)|null 鍵と足す数（引き算は負）を受ける */
    public ?\Closure $beforeIncrement = null;

    public function increment($key, $value = 1)
    {
        if ($this->beforeIncrement !== null) {
            ($this->beforeIncrement)($key, $value);
        }

        return parent::increment($key, $value);
    }
}
