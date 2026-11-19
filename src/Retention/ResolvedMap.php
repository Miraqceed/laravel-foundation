<?php

namespace Miraqceed\Foundation\Retention;

use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * アプリの台帳とパッケージの表の分を合わせたもの。アプリが同じ表を書けば、アプリの規則が勝つ
 */
final class ResolvedMap
{
    /**
     * @param  array<string, array{table: string, key: string}>  $owners
     * @param  array<string, TableRule>  $tables
     */
    private function __construct(
        private readonly array $owners,
        private readonly array $tables,
    ) {}

    /**
     * 設定 foundation.retention.map のクラスから作る。書いていなければ null
     *
     * @throws LogicException クラスが DataMap でない・台帳が食い違うとき
     */
    public static function fromConfig(): ?self
    {
        $class = config('foundation.retention.map');
        if ($class === null || $class === '') {
            return null;
        }
        if (! is_string($class) || ! is_subclass_of($class, DataMap::class)) {
            throw new LogicException('foundation.retention.map には、Miraqceed\Foundation\Retention\DataMap を実装したクラスの名前を書いてください');
        }

        return self::from(app($class));
    }

    /** @throws LogicException 台帳が食い違うとき */
    public static function from(DataMap $map): self
    {
        $app = new MapBuilder;
        $map->define($app);

        $package = new MapBuilder;
        FoundationDataMap::define($package, array_keys($app->owners()));

        $tables = $app->tables() + $package->tables();
        ksort($tables);

        foreach ($tables as $name => $rule) {
            if ($rule->isEmpty()) {
                throw new LogicException("台帳の表 {$name} に消し方がありません（erasedWith・anonymizedWith・prunedBy・prunedByCommand・kept・handledByApp のどれか）");
            }
            foreach ($rule->owners() as $owner) {
                if (! isset($app->owners()[$owner])) {
                    throw new LogicException("台帳の表 {$name} が、書いていない持ち主 {$owner} を使っています（\$map->owner('{$owner}', table: …)）");
                }
            }
        }

        return new self($app->owners(), $tables);
    }

    /** @return array<string, array{table: string, key: string}> */
    public function owners(): array
    {
        return $this->owners;
    }

    /** @return array{table: string, key: string} */
    public function owner(string $name): array
    {
        return $this->owners[$name] ?? throw new LogicException("台帳に持ち主 {$name} がありません");
    }

    /**
     * 数える表の規則。パッケージの表は、部品が有効か（DB を見るときは、表があるか）のときだけ
     *
     * @return array<string, TableRule>
     */
    public function tables(bool $lookAtDatabase = true): array
    {
        return array_filter($this->tables, fn (TableRule $rule) => $this->counts($rule, $lookAtDatabase));
    }

    /**
     * パッケージの表のうち、数えないもの（部品が無効で、表もない）も含めたすべての規則
     *
     * @return array<string, TableRule>
     */
    public function allTables(): array
    {
        return $this->tables;
    }

    private function counts(TableRule $rule, bool $lookAtDatabase): bool
    {
        $component = $rule->component();
        if ($component === null || config($component)) {
            return true;
        }

        return $lookAtDatabase && Schema::hasTable($rule->name);
    }
}
