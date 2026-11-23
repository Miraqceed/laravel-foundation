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
 * - 標準入力で、見る場所（画面のフォルダー・元の Blade の場所・外す道・期限）と、始め直しでは済んだ画面の道だけを受ける
 * - 結果を1つ見つけるたびに、決まった形の1行を標準出力に書く。中身・元の文・例外の文は書かない
 *     foundation-integrity-views 1
 *     B <道の16進>（画面を1つ始める前）   ／   R <違いの印> <道の16進>   ／   G <確かめられなかった印> <道の16進>   ／   S <止めた理由の印>
 *     C <R か G> <印> <数>（名前の件数の上限を超えて、名前を返さなかった分の、それまでの合計）
 *     end <名前つきの行の件数>
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

        $input = (string) stream_get_contents(STDIN, Limits::CHILD_INPUT_BYTES + 1);
        $data = strlen($input) > Limits::CHILD_INPUT_BYTES ? null : json_decode($input, true, 8);
        if (! is_array($data) || ! is_string($data['root'] ?? null) || ! is_array($data['dirs'] ?? null)
            || ! is_array($data['sources'] ?? null) || ! is_array($data['excluded'] ?? null)) {
            return self::FAILURE;
        }

        $this->put(self::HEAD);
        $count = 0;
        $named = ['R' => 0, 'G' => 0];
        /** @var array<string, int> $overflow 名前を返さなかった分の、種類ごとの数（鍵は「R 印」「G 印」） */
        $overflow = [];
        // 名前の件数の上限（違い 1,000・確かめられなかった 100。設計 6.4）は子にも効く。超えた分は数だけを返す
        // （画面のフォルダーに名前を撒かれて、出力の上限で画面の調べが丸ごと落ちないように）。数は 1,000 件ごとと終わりに書く
        // （途中で落ちても、それまでの数は親に届く）
        $emit = function (Finding|Gap $kind, string $path) use (&$count, &$named, &$overflow): void {
            $mark = $kind instanceof Finding ? 'R' : 'G';
            $limit = $mark === 'R' ? Limits::FINDINGS_KEPT : Limits::GAPS_KEPT;
            if ($named[$mark] < $limit) {
                $named[$mark]++;
                $count++;
                $this->put($mark.' '.$kind->value.' '.bin2hex($path));

                return;
            }
            $key = $mark.' '.$kind->value;
            $overflow[$key] = ($overflow[$key] ?? 0) + 1;
            if ($overflow[$key] % 1000 === 0) {
                $this->put('C '.$key.' '.$overflow[$key]);
            }
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
            onStart: fn (string $path) => $this->put('B '.bin2hex($path)),
            skip: array_values(array_filter((array) ($data['skip'] ?? []), 'is_string')),
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
        foreach ($overflow as $key => $number) {
            $this->put('C '.$key.' '.$number);
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
