<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * public/hot が残ると、本番の画面が開発のサーバー（npm run dev）を読みに行く
 */
final class ViteHot implements Check
{
    public function id(): string
    {
        return 'vite_hot';
    }

    public function label(): string
    {
        return 'Vite の開発のサーバーの印（public/hot）がない';
    }

    public function run(): Result
    {
        return file_exists(public_path('hot')) ? Result::fail() : Result::ok();
    }
}
