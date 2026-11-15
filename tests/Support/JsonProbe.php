<?php

namespace Miraqceed\Foundation\Tests\Support;

use Closure;

/** 全体のミドルウェアの後ろで、解かれた JSON の中身（Trim などを通った後か）を控える */
class JsonProbe
{
    public static ?array $json = null;

    public function handle($request, Closure $next)
    {
        self::$json = $request->isJson() ? $request->json()->all() : null;

        return $next($request);
    }
}
