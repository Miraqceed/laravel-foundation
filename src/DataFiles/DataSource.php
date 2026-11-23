<?php

namespace Miraqceed\Foundation\DataFiles;

use Carbon\CarbonImmutable;

/**
 * 外から取るデータのファイルの種類（アプリが書く）。foundation.data_files.sources にクラス名を並べると、
 * foundation:data-files が「取る → 同じディレクトリの一時のファイルに解く → verify() → 版を比べる → 入れ替える」を行う。
 *
 * 容器から作る（命令を流したとき・読む側の助けを呼んだとき）。プロバイダーの起動では作らない。
 */
interface DataSource
{
    /** 置き場所・ロック・知らせの鍵（1〜64字の ASCII の英数字・-・_）。変えると前のファイルを読まなくなる */
    public function name(): string;

    /**
     * 試す順の取り方。404 を「まだ出ていない」として次へ進めるものは notFoundMeansNotYet() を付ける。
     *
     * @return iterable<Download>
     */
    public function downloads(CarbonImmutable $now): iterable;

    /** 取る大きさ・解いた大きさの上下・減りの止め・古さ（既定を持たない。必ず渡す） */
    public function limits(): Limits;

    /**
     * 使う側と同じ読み方で開き、決まった答えが返るかを見て、版を返す。読めなければ投げる。
     * 取ったファイル（一時のファイル）にも、入っているファイルにも呼ぶ。
     * 整数は数の順、文字列は文字の順で比べる。版を持たないデータは null（中身の SHA-256 で比べる。古い版で戻すことは防げない）
     */
    public function verify(string $path): string|int|null;

    /** 置き場所にファイルがないときに読む写し（アプリの倉庫に入れたもの）。なければ null */
    public function fallbackPath(): ?string;
}
