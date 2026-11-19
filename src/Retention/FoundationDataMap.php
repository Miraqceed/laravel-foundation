<?php

namespace Miraqceed\Foundation\Retention;

use Miraqceed\Foundation\Accounts\EmailChange;
use Miraqceed\Foundation\Ai\AiUsage;
use Miraqceed\Foundation\Monitoring\Alerts\Alert;
use Miraqceed\Foundation\Notices\Notice;
use Miraqceed\Foundation\OneTimeCodes\OneTimeCode;

/**
 * パッケージ自身の表の台帳の分。アプリの台帳に足す（アプリが同じ表を書けば、アプリが勝つ）。
 * 部品を有効にしておらず、表もなければ数えない（forComponent）。
 * Laravel の表（failed_jobs・sessions など）の分は持たない。保存の期間はアプリが決める
 */
final class FoundationDataMap
{
    /** @param  list<string>  $owners  アプリの台帳の持ち主の名前 */
    public static function define(MapBuilder $map, array $owners): void
    {
        $map->table('foundation_alerts')->prunedBy(Alert::class)->forComponent('foundation.monitoring.enabled');
        // 知らせの行と一緒に、外部キーの cascade で消える
        $map->table('foundation_alert_deliveries')->prunedBy(Alert::class)->forComponent('foundation.monitoring.enabled');
        $map->table('foundation_task_runs')->kept('定期の処理の名前ごとの最後の時刻。個人の情報を持たない')->forComponent('foundation.monitoring.enabled');

        $map->table('foundation_ai_usages')->prunedBy(AiUsage::class)->forComponent('foundation.ai.enabled');
        $map->table('foundation_ai_totals')->kept('日ごとの費用の合計。個人の情報を持たない')->forComponent('foundation.ai.enabled');
        $map->table('foundation_ai_notices')->kept('費用の上限の知らせの印（日ごと）。個人の情報を持たない')->forComponent('foundation.ai.enabled');

        $map->table('foundation_one_time_codes')->prunedBy(OneTimeCode::class)->forComponent('foundation.one_time_codes.enabled');
        $map->table('foundation_notices')->prunedBy(Notice::class)->forComponent('foundation.notices.enabled');

        // 古いアドレス・新しいアドレスを持つ。アカウントを消したら、戻すリンクの期限を待たずに消す（取り戻すアカウントがない）
        $emailChanges = $map->table('foundation_email_changes')->prunedBy(EmailChange::class)->forComponent('foundation.email_changes.enabled');
        foreach ($owners as $owner) {
            $emailChanges->erasedWith($owner, morph: 'account');
        }
    }
}
