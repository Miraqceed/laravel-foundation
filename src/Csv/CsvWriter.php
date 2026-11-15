<?php

namespace Miraqceed\Foundation\Csv;

final class CsvWriter
{
    private bool $started = false;

    /** @param resource $stream */
    public function __construct(private $stream, private readonly Encoding $encoding)
    {
    }

    /** @param array<int, mixed> $cells */
    public function writeRow(array $cells): void
    {
        if (! $this->started) {
            $this->started = true;

            // BOM は Excel が UTF-8 と判別するために要る。Shift_JIS では付けない
            if ($this->encoding === Encoding::Utf8Bom && @fwrite($this->stream, "\xEF\xBB\xBF") === false) {
                throw new CsvWriteException('CSV の書き込みに失敗しました。');
            }
        }

        $cells = array_map(fn ($value) => CellGuard::guard((string) $value), $cells);

        if ($this->encoding === Encoding::ShiftJis) {
            $cells = $this->toShiftJis($cells);
        }

        // エスケープ文字は渡さない（\" で引用符が閉じて次のセルに式を作れる。PHP 8.4 では省くと非推奨の警告も出る）
        if (@fputcsv($this->stream, $cells, ',', '"', '', "\r\n") === false) {
            throw new CsvWriteException('CSV の書き込みに失敗しました。');
        }
    }

    /**
     * 変換できない文字は落とさず「?」にする。行がずれるより読めない方がよい。
     *
     * @param  array<int, string>  $cells
     * @return array<int, string>
     */
    private function toShiftJis(array $cells): array
    {
        $previous = mb_substitute_character();
        mb_substitute_character(0x3F);

        try {
            return array_map(fn (string $v) => mb_convert_encoding($v, 'SJIS-win', 'UTF-8'), $cells);
        } finally {
            mb_substitute_character($previous);
        }
    }
}
