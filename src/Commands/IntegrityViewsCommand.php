<?php

namespace Miraqceed\Foundation\Commands;

use Illuminate\Console\Command;
use Miraqceed\Foundation\Integrity\Checks\CompiledViews;
use Miraqceed\Foundation\Integrity\Deadline;
use Miraqceed\Foundation\Integrity\Finding;
use Miraqceed\Foundation\Integrity\Gap;
use Miraqceed\Foundation\Integrity\Limits;
use Miraqceed\Foundation\Integrity\MemoryGuard;
use Throwable;

/**
 * 画面の調べの子プロセス（内向きの命令。foundation:integrity-check が流す。説明書に書かない）。
 * 子が落ちても（メモリー・時間・異常な終わり）、親は「画面の調べが落ちた」とそれまでの発見を出して正しく終わる。
 *
 * - 標準入力で、見る場所（画面のフォルダー・元の Blade の場所・外す道・期限）だけを受ける
 * - 結果を1つ見つけるたびに、決まった形の1行を標準出力に書く。中身・元の文・例外の文は書かない
 *     foundation-integrity-views 1
 *     R <違いの印> <道の16進>   ／   G <確かめられなかった印> <道の16進>   ／   S <止めた理由の印>
 *     end <件数>
 * - SAPI が cli でなければ（php-cgi は CGI の頭を標準出力に書く）、頭の行を書かずに 3 で終える（親は子を使えないとみなす）
 */
class IntegrityViewsCommand extends Command
{
    public const NOT_CLI = 3;

    public const HEAD = 'foundation-integrity-views 1';

    protected $signature = 'foundation:integrity-views';

    protected $description = '改ざんの検知の、画面の調べの子プロセス（内向き）';

    protected $hidden = true;

    /** 試しの継ぎ目：SAPI を偽る */
    public static ?string $sapiForTests = null;

    public function handle(): int
    {
        if ((self::$sapiForTests ?? PHP_SAPI) !== 'cli') {
            return self::NOT_CLI;
        }

        $input = (string) stream_get_contents(STDIN, Limits::HEAD_BYTES + 1);
        $data = strlen($input) > Limits::HEAD_BYTES ? null : json_decode($input, true, 8);
        if (! is_array($data) || ! is_string($data['root'] ?? null) || ! is_array($data['dirs'] ?? null)
            || ! is_array($data['sources'] ?? null) || ! is_array($data['excluded'] ?? null)) {
            return self::FAILURE;
        }

        $this->put(self::HEAD);
        $count = 0;
        $emit = function (Finding|Gap $kind, string $path) use (&$count): void {
            $count++;
            $this->put(($kind instanceof Finding ? 'R ' : 'G ').$kind->value.' '.bin2hex($path));
        };

        $checker = new CompiledViews(
            root: $data['root'],
            sourceDirs: array_values(array_filter($data['sources'], 'is_string')),
            excluded: array_values(array_filter($data['excluded'], 'is_string')),
            deadline: new Deadline((float) ($data['seconds'] ?? 60)),
            guard: new MemoryGuard,
            ratio: 0.5,
            emit: $emit,
            generatedFolders: array_values(array_filter((array) ($data['generated'] ?? []), 'is_string')),
            inlineDirs: array_values(array_filter((array) ($data['inline'] ?? []), 'is_string')),
        );

        foreach ($data['dirs'] as $dir) {
            if (! is_array($dir) || ! is_string($dir['path'] ?? null) || ! is_string($dir['shown'] ?? null)) {
                continue;
            }
            try {
                $stopped = $checker->check($dir['path'], $dir['shown']);
            } catch (Throwable) {
                return self::FAILURE;
            }
            if ($stopped !== null) {
                $this->put('S '.$stopped->value);
                break;
            }
        }
        $this->put('end '.$count);

        return self::SUCCESS;
    }

    private function put(string $line): void
    {
        fwrite(STDOUT, $line."\n");
        fflush(STDOUT);
    }
}
