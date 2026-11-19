<?php

namespace Miraqceed\Foundation\Tests\Support\Retention;

use Miraqceed\Foundation\Retention\Anonymize;
use Miraqceed\Foundation\Retention\DataMap;
use Miraqceed\Foundation\Retention\MapBuilder;

/** 試しの台帳（作り物のアプリ）。CreatesRetentionTables の表をすべて書く */
class ShopDataMap implements DataMap
{
    public function define(MapBuilder $map): void
    {
        $map->owner('user', table: 'users');

        $map->table('users')->anonymizedWith('user', key: 'id', erase: [
            'name' => '退会したユーザー',
            'email' => Anonymize::unique('deleted-{id}@anonymized.invalid'),
            'password' => '',
            'remember_token' => null,
        ], keep: ['id', 'plan', 'created_at', 'deleted_at'], reason: '請求と集計の帰属先');

        $map->table('posts')->erasedWith('user', column: 'user_id');
        $map->table('post_images')->erasedWith('user', via: ['posts', 'post_id', 'user_id']);
        $map->table('contacts')->anonymizedWith('user', key: 'user_id', erase: [
            'email' => Anonymize::unique('contact-{id}@anonymized.invalid'),
            'phone' => null,
        ], keep: ['id', 'user_id', 'label'], reason: '問い合わせの件数の集計');

        $map->table('clicks')->prunedBy(Click::class)->erasedWith('user', column: 'user_id');
        $map->table('failed_jobs')->prunedByCommand('queue:prune-failed');
        $map->table('invoices')->kept('請求の写し（法令で残す）');
        $map->table('reviews')->kept('公開した評価');
        $map->table('screenshots')->handledByApp('ScreenshotStore::purge（ストレージの画像も消す）');
    }
}
