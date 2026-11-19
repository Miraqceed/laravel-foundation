<?php

namespace Miraqceed\Foundation\Notices;

/** OnceNotices::send() の結果 */
enum NoticeResult: string
{
    /** 送った（渡した関数が返った） */
    case Sent = 'sent';

    /** 送らなかった。送った印がある、またはほかの処理が取って送っているところ */
    case AlreadySent = 'already_sent';

    /** 送れなかった（渡した関数が投げた）。印は戻した（keepOnFailure なら残した）。記録に残した */
    case Failed = 'failed';

    /** トランザクションの中で呼ばれた。取る・送るは確定の後に行う（巻き戻れば行わない）。確定の後の結果は返らない */
    case Deferred = 'deferred';
}
