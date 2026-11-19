<?php

namespace Miraqceed\Foundation\Notices;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * 知らせの印の行。保存の期限（keep_until）を過ぎた行を model:prune で消すためのモデル（取る・送るは OnceNotices で行う）。
 * 時刻の列は UTC の文字列。アプリが schedule に入れる：
 * Schedule::command('model:prune', ['--model' => [Notice::class]])->daily();
 *
 * @property int $id
 * @property string $key
 * @property string $key_hash
 * @property string $claimed_at UTC
 * @property ?string $sent_at UTC
 * @property string $keep_until UTC
 */
class Notice extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $table = OnceNotices::TABLE;

    protected $guarded = [];

    /** token は取った処理の印。配列・JSON にしても出さない */
    protected $hidden = ['token'];

    /** 期限を過ぎた行。印が消えると、同じ鍵の知らせをもう一度送れるようになる */
    public function prunable(): Builder
    {
        return static::query()->where('keep_until', '<', CarbonImmutable::now('UTC')->format('Y-m-d H:i:s'));
    }
}
