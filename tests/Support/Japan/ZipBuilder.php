<?php

namespace Miraqceed\Foundation\Tests\Support\Japan;

/**
 * 試しのための作り物の ZIP。中央ディレクトリの名乗り（大きさ・CRC・方式・旗）を、わざと違えて書ける
 */
final class ZipBuilder
{
    /** @var list<array{name: string, data: string, method: int, flags: int, size: ?int, crc: ?int, date: string}> */
    private array $files = [];

    public static function make(): self
    {
        return new self;
    }

    /** 1つのファイルだけの ZIP（deflate） */
    public static function single(string $name, string $data, string $date = '2026-10-30'): string
    {
        return self::make()->add($name, $data, date: $date)->build();
    }

    /**
     * @param  int|null  $size  名乗る展開後の大きさ（null なら本当の長さ）
     * @param  int|null  $crc  名乗る CRC-32（null なら本当の値）
     */
    public function add(string $name, string $data, int $method = 8, int $flags = 0, ?int $size = null, ?int $crc = null, string $date = '2026-10-30'): self
    {
        $this->files[] = compact('name', 'data', 'method', 'flags', 'size', 'crc', 'date');

        return $this;
    }

    public function build(): string
    {
        $locals = '';
        $central = '';
        foreach ($this->files as $file) {
            $body = $file['method'] === 8 ? gzdeflate($file['data']) : $file['data'];
            [$year, $month, $day] = array_map('intval', explode('-', $file['date']));
            $dosDate = (($year - 1980) << 9) | ($month << 5) | $day;
            $crc = $file['crc'] ?? crc32($file['data']);
            $size = $file['size'] ?? strlen($file['data']);
            $offset = strlen($locals);

            $locals .= pack('VvvvvvVVVvv', 0x04034B50, 20, $file['flags'], $file['method'], 0, $dosDate, $crc, strlen($body), $size, strlen($file['name']), 0)
                .$file['name'].$body;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014B50, 20, 20, $file['flags'], $file['method'], 0, $dosDate, $crc, strlen($body), $size, strlen($file['name']), 0, 0, 0, 0, 0, $offset)
                .$file['name'];
        }
        $count = count($this->files);

        return $locals.$central.pack('VvvvvVVv', 0x06054B50, 0, 0, $count, $count, strlen($central), strlen($locals), 0);
    }
}
