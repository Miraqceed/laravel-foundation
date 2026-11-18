<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Illuminate\Foundation\Application;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;

/**
 * DB の接続の mask_bindings_in_exception_messages（Laravel 13.27.0 から）が真か。真でないと、失敗した SQL の例外の文に、
 * バインドした値（訪問者の IP・メールアドレスなど）がそのまま入り、ログに残る。接続の名前だけを出す。
 *
 * 見る接続は、既定の接続と foundation.deploy_check.connections。
 * 真でも、DB の誤りの文そのもの（一意の違反の Duplicate entry・DETAIL）には値が残るので、いつもその旨を添える。
 * Laravel の雛形の既定（設定なし）で新しいアプリが全部落ちないよう、fail でなく later にする。
 */
final class DbMaskedBindings implements Check
{
    private const SINCE = '13.27.0';

    private const REMAINS = '一意の違反の文（Duplicate entry・DETAIL）には、有効にしても値が残る（docs/failures.md）';

    public function id(): string
    {
        return 'db_masked_bindings';
    }

    public function label(): string
    {
        return 'DB の失敗の例外の文に、バインドした値を入れない（mask_bindings_in_exception_messages）';
    }

    public function run(): Result
    {
        if (version_compare(Application::VERSION, self::SINCE, '<')) {
            return Result::info('この版の Laravel にはない設定（'.self::SINCE.' から）。'.self::REMAINS);
        }

        $unmasked = [];
        foreach (self::connections() as $name) {
            if (config("database.connections.{$name}.mask_bindings_in_exception_messages") !== true) {
                $unmasked[] = $name;
            }
        }

        if ($unmasked !== []) {
            return Result::later('真でない接続：'.implode('・', $unmasked).'。'.self::REMAINS);
        }

        return Result::ok(self::REMAINS);
    }

    /** @return list<string> */
    private static function connections(): array
    {
        $names = [config('database.default'), ...array_values((array) config('foundation.deploy_check.connections', []))];

        return array_values(array_unique(array_filter($names, fn (mixed $name) => is_string($name) && $name !== '')));
    }
}
