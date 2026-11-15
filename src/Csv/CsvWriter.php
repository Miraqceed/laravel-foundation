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
        $cells = array_map(fn ($value) => CellGuard::guard((string) $value), $cells);

        if ($this->encoding === Encoding::ShiftJis) {
            $cells = $this->toShiftJis($cells);
        }

        // エスケープ文字は渡さない（\" で引用符が閉じて次のセルに式を作れる。PHP 8.4 では省くと非推奨の警告も出る）
        $buffer = fopen('php://memory', 'w+');
        fputcsv($buffer, $cells, ',', '"', '', "\r\n");
        rewind($buffer);
        $line = (string) stream_get_contents($buffer);
        fclose($buffer);

        // BOM は Excel が UTF-8 と判別するために要る。Shift_JIS では付けない。書けてから「始めた」とする
        if (! $this->started) {
            if ($this->encoding === Encoding::Utf8Bom) {
                $this->put("\xEF\xBB\xBF");
            }
            $this->started = true;
        }

        $this->put($line);
    }

    /** 書けたバイト数が足りなければ（false も短い書き込みも）失敗とする */
    private function put(string $data): void
    {
        $written = @fwrite($this->stream, $data);

        if ($written !== strlen($data)) {
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
