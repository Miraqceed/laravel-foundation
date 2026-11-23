<?php

namespace Miraqceed\Foundation\Tests\Support\Integrity;

/**
 * 試しの間だけ、試しのプロセスの memory_limit を外す（-1）。調べの網（memory_limit の 60%）は、そのプロセスが使っている分と
 * 比べるので、ほかの試しで膨らんだ PHPUnit の中では、PHP の既定の 128M だと始めから網を超え、試しが PHPUnit の memory_limit に
 * 左右される。メモリーの上限を見る試しは、別のプロセス（子・RunInSeparateProcess）で、決めた memory_limit を渡して流す
 */
trait OwnMemoryLimit
{
    private ?string $memoryLimitBefore = null;

    protected function liftMemoryLimit(): void
    {
        $this->memoryLimitBefore ??= (string) ini_get('memory_limit');
        ini_set('memory_limit', '-1');
    }

    protected function restoreMemoryLimit(): void
    {
        if ($this->memoryLimitBefore !== null) {
            ini_set('memory_limit', $this->memoryLimitBefore);
            $this->memoryLimitBefore = null;
        }
    }
}
