<?php

namespace Miraqceed\Foundation\TwoFactor;

use Illuminate\Database\Eloquent\Model;

/**
 * 確認コードの間違いが続き、ロックになった（上限に届いた失敗と、ロックが明けた後の失敗のたびに出る）。
 * 誰に知らせるか（運営へのメールなど）はアプリが決める。秘密の鍵もコードも持たない
 */
final class TwoFactorLocked
{
    public function __construct(public readonly Model $user) {}
}
