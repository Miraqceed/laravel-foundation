<?php

namespace Miraqceed\Foundation\Accounts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * メールアドレスの変更を戻すときに、乗っ取った人が作ったものを消すアプリの差し込み（設定 foundation.email_changes.lockdown）。
 *
 * パッケージは、差し込みと別に必ず、パスワード（乱数の値のハッシュ）・remember のトークン・すべてのセッション・
 * 再設定のトークン・まだ確かめていない頼みを消す。API キー・外部の連携・二段階の認証などはアプリがここで消す
 */
interface AccountLockdown
{
    /**
     * 確かめたときに変更の行に残す値（例：その時に連携していた外部のアカウントの id）。秘密を入れない。JSON にできるもの
     *
     * @return array<array-key, mixed>
     */
    public function snapshot(Model&Authenticatable $user): array;

    /**
     * 戻すときに、同じトランザクションの中で呼ぶ。投げたら戻すこと全体が巻き戻る
     *
     * @param  array<array-key, mixed>  $snapshot  確かめたときの snapshot()
     */
    public function lockdown(Model&Authenticatable $user, array $snapshot): void;
}
