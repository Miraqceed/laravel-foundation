<?php

namespace Miraqceed\Foundation\Accounts;

/**
 * メールアドレスの変更を確かめた・戻した結果。値は綴りを変えない（アプリが文を分けるのに使う）。
 *
 * 断りの順：invalid → not_requester（確かめるときだけ）→ cancelled・used・expired → changed → taken・held。
 * トークンが合わなければ理由を分けない（invalid）。本人でない人には、切れた・使ったも見せない
 */
enum EmailChangeStatus: string
{
    /** 新しいアドレスに切り替えた */
    case Confirmed = 'confirmed';

    /** 古いアドレスに戻した */
    case Undone = 'undone';

    /** トークンの形が違う・無い・違う（理由を分けない） */
    case Invalid = 'invalid';

    /** ログイン中の利用者が、頼んだ本人でない（未ログインも） */
    case NotRequester = 'not_requester';

    /** 後の頼み・cancelPending()・戻すで取り消した */
    case Cancelled = 'cancelled';

    /** 確かめた・戻した */
    case Used = 'used';

    /** 期限が過ぎた（ちょうども） */
    case Expired = 'expired';

    /** 頼んだ後に今のアドレスが変わった（戻すときは、変更のつながりをたどれない） */
    case Changed = 'changed';

    /** アドレスが、ほかのアカウントに使われている */
    case Taken = 'taken';

    /** アドレスが、ほかのアカウントの押さえ（戻せる間の古いアドレス） */
    case Held = 'held';
}
