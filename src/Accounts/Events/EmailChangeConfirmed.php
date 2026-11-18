<?php

namespace Miraqceed\Foundation\Accounts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * 新しいアドレスに切り替えた（コミットの後に出る）。決済の顧客のアドレス・監査の記録など、アプリが聞き手で直す。トークンは入れない
 */
final class EmailChangeConfirmed implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Model $account,
        public readonly int $changeId,
        public readonly string $oldEmail,
        public readonly string $newEmail,
    ) {}
}
