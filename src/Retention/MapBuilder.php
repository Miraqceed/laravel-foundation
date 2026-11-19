<?php

namespace Miraqceed\Foundation\Retention;

use InvalidArgumentException;
use LogicException;

/**
 * 台帳を組み立てる。DataMap::define() に渡る。書く順は問わない（消す順は Erasure が外部キーから決める）
 */
final class MapBuilder
{
    /** @var array<string, array{table: string, key: string}> */
    private array $owners = [];

    /** @var array<string, TableRule> */
    private array $tables = [];

    /** 持ち主（退会・解約で消す単位）。消すときに握る行の表と鍵 */
    public function owner(string $name, string $table, string $key = 'id'): self
    {
        if ($name === '' || $table === '' || $key === '') {
            throw new InvalidArgumentException('持ち主の名前・表・鍵は空にできません');
        }
        if (isset($this->owners[$name])) {
            throw new LogicException("持ち主 {$name} を2回書いています");
        }

        $this->owners[$name] = ['table' => $table, 'key' => $key];

        return $this;
    }

    /** 表の消し方を書く。同じ表をもう一度書くと、同じ規則に書き足す */
    public function table(string $name): TableRule
    {
        if ($name === '') {
            throw new InvalidArgumentException('表の名前は空にできません');
        }

        return $this->tables[$name] ??= new TableRule($name);
    }

    /** @return array<string, array{table: string, key: string}> */
    public function owners(): array
    {
        return $this->owners;
    }

    /** @return array<string, TableRule> */
    public function tables(): array
    {
        return $this->tables;
    }
}
