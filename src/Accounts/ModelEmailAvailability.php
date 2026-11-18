<?php

namespace Miraqceed\Foundation\Accounts;

use Illuminate\Database\Eloquent\Model;

/**
 * 既定：そのアカウントのモデルの表の email の列（foundation.email_changes.email_column）に、ほかの行で同じ値があるか。
 * 値は字のとおりに比べる（DB の照合順序による）。保存の値をそろえるのはアプリ（そろえて保存し、そろえて探す）
 */
final class ModelEmailAvailability implements EmailAvailability
{
    public function isTaken(string $email, Model $except): bool
    {
        return $except->newQueryWithoutScopes()
            ->where(EmailChanges::emailColumn(), $email)
            ->whereKeyNot($except->getKey())
            ->exists();
    }
}
