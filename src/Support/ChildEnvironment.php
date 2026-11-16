<?php

namespace Miraqceed\Foundation\Support;

/**
 * 子プロセスに渡す環境変数の配列を作る。親の環境（.env の秘密を含む）を受け継がせず、挙げた名前だけを渡す。
 *
 * Symfony Process（と、それを使う Laravel の Process）は、渡した環境に親の環境（$_ENV と、$_SERVER と名前が重なる getenv()）を
 * 足して子に渡す。Laravel は .env の値を $_ENV と $_SERVER に入れるので、何もしないと子は DB_PASSWORD・APP_KEY などを受け継ぐ。
 * 受け継がせないには、名前ごとに false を渡す（Symfony は false の名前を子に渡さない）。
 *
 * 使い方：Process::env(ChildEnvironment::only(['PATH', 'HOME']))->run([...])
 */
final class ChildEnvironment
{
    /**
     * @param  list<string>  $names  親の値のまま子に渡す名前（親に無い名前は足さない）
     * @param  array<string, string>  $set  決めて子に渡す名前と値（$names より勝つ）
     * @return array<string, string|false> 親の名前は全部 false、挙げた名前は値
     */
    public static function only(array $names, array $set = []): array
    {
        $parent = self::parent();
        $environment = array_fill_keys(array_map('strval', array_keys($parent)), false);

        foreach ($names as $name) {
            if (isset($parent[$name]) && is_string($parent[$name])) {
                $environment[$name] = $parent[$name];
            }
        }

        return array_replace($environment, $set);
    }

    /**
     * 親の環境の名前と値（getenv()・$_ENV・$_SERVER。値は getenv() を先に見る）
     *
     * @return array<string, mixed>
     */
    private static function parent(): array
    {
        $env = getenv();

        return (is_array($env) ? $env : []) + $_ENV + $_SERVER;
    }
}
