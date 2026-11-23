<?php

namespace Miraqceed\Foundation\DataFiles;

use InvalidArgumentException;

/**
 * 1つの種類の上限。既定を持たない（データによって、ふつうの大きさと減りの幅が違う。考えずに同じ値を使わない）。
 * 違う値は作るときに InvalidArgumentException
 */
final class Limits
{
    /**
     * @param  int  $maxDownloadBytes  取る大きさの上限（受けている途中で止める）
     * @param  int  $maxBytes  解いた大きさの上限（gzip を解いている途中で止める）
     * @param  int  $minBytes  解いた大きさの下限（空・誤りのページを断る）
     * @param  float|null  $maxShrink  入っているファイルより、この割合を超えて小さければ断る（0 より大きく 1 より小さい。null なら比べない。--force で通す）
     * @param  int|null  $staleAfterDays  最後に更新を流して成功してから、この日数を過ぎれば foundation:deploy-check が later（null なら見ない）
     */
    public function __construct(
        public readonly int $maxDownloadBytes,
        public readonly int $maxBytes,
        public readonly int $minBytes,
        public readonly ?float $maxShrink,
        public readonly ?int $staleAfterDays,
    ) {
        if ($maxDownloadBytes < 1 || $maxBytes < 1 || $minBytes < 1) {
            throw new InvalidArgumentException('データのファイルの大きさの上限と下限は 1 以上にしてください');
        }
        if ($minBytes > $maxBytes) {
            throw new InvalidArgumentException('データのファイルの大きさの下限は、上限より大きくできません');
        }
        if ($maxShrink !== null && ! ($maxShrink > 0.0 && $maxShrink < 1.0)) {
            throw new InvalidArgumentException('データのファイルの減りの止めは 0 より大きく 1 より小さい割合か null にしてください');
        }
        if ($staleAfterDays !== null && $staleAfterDays < 1) {
            throw new InvalidArgumentException('データのファイルの古さの日数は 1 以上か null にしてください');
        }
    }
}
