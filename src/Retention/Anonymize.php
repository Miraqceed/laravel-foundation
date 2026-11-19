<?php

namespace Miraqceed\Foundation\Retention;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;
use InvalidArgumentException;
use LogicException;

/**
 * 匿名化で書き込む値のうち、行ごとに違う値（一意の列のため）。
 * Anonymize::unique('deleted-{id}@anonymized.invalid')：{id} を行の列（既定 id）の値に替える
 */
final class Anonymize
{
    private function __construct(
        public readonly string $pattern,
        public readonly string $column,
    ) {}

    /** @throws InvalidArgumentException 型に {id} がないとき */
    public static function unique(string $pattern, string $column = 'id'): self
    {
        if (! str_contains($pattern, '{id}')) {
            throw new InvalidArgumentException('Anonymize::unique() の型には {id} を入れてください');
        }

        return new self($pattern, $column);
    }

    /** UPDATE に書く式。文字の連結は DB ごとに組み立てる（MySQL は CONCAT、SQLite・PostgreSQL は ||） */
    public function expression(Connection $db): Expression
    {
        $column = 'CAST('.$db->getQueryGrammar()->wrap($this->column).' AS '.($this->isMySql($db) ? 'CHAR' : 'TEXT').')';

        $parts = [];
        foreach (explode('{id}', $this->pattern) as $i => $text) {
            if ($i > 0) {
                $parts[] = $column;
            }
            if ($text !== '') {
                $parts[] = $db->escape($text);
            }
        }

        return new Expression($this->isMySql($db) ? 'CONCAT('.implode(', ', $parts).')' : implode(' || ', $parts));
    }

    private function isMySql(Connection $db): bool
    {
        return match ($db->getDriverName()) {
            'mysql', 'mariadb' => true,
            'pgsql', 'sqlite' => false,
            default => throw new LogicException('Anonymize::unique() は MySQL・MariaDB・PostgreSQL・SQLite だけで使えます'),
        };
    }
}
