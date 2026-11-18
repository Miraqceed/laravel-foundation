<?php

namespace Miraqceed\Foundation\Accounts;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * メールアドレスの変更の行。古い行を model:prune で消すためのモデル（頼む・確かめる・戻すは EmailChanges で行う）。
 * 時刻の列は UTC の文字列（時差なしの dateTime）。datetime に型づけしない（アプリの時間帯の時刻と読まれる）。
 * アプリが schedule に入れる：Schedule::command('model:prune', ['--model' => [EmailChange::class]])->daily();
 *
 * @property int $id
 * @property string $account_type
 * @property string $account_id
 * @property string $old_email
 * @property string $new_email
 * @property string $expires_at UTC
 * @property ?string $confirmed_at UTC
 * @property ?string $undone_at UTC
 * @property ?string $cancelled_at UTC
 * @property ?string $undo_expires_at UTC
 */
class EmailChange extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $table = EmailChanges::TABLE;

    protected $guarded = [];

    /**
     * もう使えない行のうち、prune_days 日（既定 7。1未満は1）より前のもの：確かめないまま切れた・取り消した・戻した・戻す期限が過ぎた。
     * 戻せる間の行（押さえ）は消さない
     */
    public function prunable(): Builder
    {
        $days = max(1, (int) config('foundation.email_changes.prune_days', 7));
        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->format('Y-m-d H:i:s');

        return static::query()->where(fn (Builder $query) => $query
            ->where(fn (Builder $pending) => $pending->whereNull('confirmed_at')->where('expires_at', '<', $cutoff))
            ->orWhere('cancelled_at', '<', $cutoff)
            ->orWhere('undone_at', '<', $cutoff)
            ->orWhere('undo_expires_at', '<', $cutoff));
    }
}
