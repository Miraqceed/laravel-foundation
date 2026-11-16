<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/** 道具を使う会話の試しの道具。呼ばれた回数を数える */
class AiLookupTool implements Tool
{
    public static int $calls = 0;

    public function description(): Stringable|string
    {
        return 'Look up a fact.';
    }

    public function handle(Request $request): Stringable|string
    {
        self::$calls++;

        return 'found';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
