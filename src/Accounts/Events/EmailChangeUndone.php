<?php

namespace Miraqceed\Foundation\Accounts\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * 古いアドレスに戻した（コミットの後に出る）。運営に知らせる・監査の記録など、アプリが聞き手で行う。トークンは入れない
 */
final class EmailChangeUndone implements ShouldDispatchAfterCommit
{
    public function __construct(
        public readonly Model $account,
        /** @var list<int> 戻した変更の行（戻すリンクの行と、その後にたどった行） */
        public readonly array $changeIds,
        /** 戻したアドレス */
        public readonly string $restoredEmail,
        /** 戻す前の今のアドレス */
        public readonly string $replacedEmail,
    ) {}
}
