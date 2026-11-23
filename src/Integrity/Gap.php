<?php

namespace Miraqceed\Foundation\Integrity;

/** 確かめられなかったところの種類。値は出力の印（変えない。台本が読む） */
enum Gap: string
{
    case NoSnapshot = 'no-snapshot';
    case BadSnapshot = 'bad-snapshot';
    case OtherRoot = 'other-root';
    case BrokenSnapshot = 'broken-snapshot';
    case SnapshotUnfinished = 'snapshot-unfinished';
    case SettingsDiffer = 'settings-differ';
    case ExcludesLoadPaths = 'excludes-load-paths';
    case ExcludesPublicPhp = 'excludes-public-php';
    case GeneratedViewsRefused = 'generated-views-refused';
    case SqliteNotExcluded = 'sqlite-not-excluded';
    case PublicOutside = 'public-outside';
    case Unopened = 'unopened';
    case TooMany = 'too-many';
    case TooDeep = 'too-deep';
    case TimedOut = 'timed-out';
    case Memory = 'memory';
    case NoStub = 'no-stub';
    case Failed = 'failed';
    case ViewsFailed = 'views-failed';
    case ViewTooLarge = 'view-too-large';
    case PreviousViewsCrashed = 'previous-views-crashed';
    case MarkUnwritable = 'mark-unwritable';

    /** 道ごとに出る種類（違いと別の枠の件数の上限に数える）。ほかは、件数の上限によらずいつも出す */
    public function isPerPath(): bool
    {
        return in_array($this, [self::Unopened, self::TooMany, self::TooDeep, self::ViewTooLarge], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::NoSnapshot => 'ハッシュの一覧がない',
            self::BadSnapshot => 'ハッシュの一覧を読めない',
            self::OtherRoot => 'ハッシュの一覧が、ほかの木のもの',
            self::BrokenSnapshot => 'ハッシュの一覧が壊れている',
            self::SnapshotUnfinished => 'ハッシュの一覧の終わりまで確かめられなかった',
            self::SettingsDiffer => '見る場所の設定が一覧と違う（一覧を作り直す）',
            self::ExcludesLoadPaths => '外すもの・公開される場所の設定が、調べが読み込む場所に掛かっている',
            self::ExcludesPublicPhp => '外すもの（exclude）が、公開される場所の PHP として動く名前か public_php に掛かっている（その値は使わない。公開される変わる場所は served に書く）',
            self::GeneratedViewsRefused => '部品が作る画面の場所の設定を使えない（コンパイルした画面のフォルダーの下だけを許せる）',
            self::SqliteNotExcluded => '木の中の SQLite のファイルを外せない（SQLite の頭の印がない・PHP として動く名前・調べが読み込む場所の中・公開される場所の中のどれか。違いとして見る）',
            self::PublicOutside => '公開ディレクトリが木の public でない',
            self::Unopened => '開けないフォルダー',
            self::TooMany => '項目が多すぎるフォルダー',
            self::TooDeep => '木が深すぎる',
            self::TimedOut => '時間の上限で打ち切った',
            self::Memory => 'メモリーの上限で止めた',
            self::NoStub => 'メンテナンスの入口と比べる雛形がない',
            self::Failed => '調べの途中で、思わぬ誤りが起きた',
            self::ViewsFailed => '画面の調べが落ちた',
            self::ViewTooLarge => '画面が大きすぎる',
            self::PreviousViewsCrashed => '前の回は画面の調べの途中で落ちた',
            self::MarkUnwritable => '印のファイルを書けない',
        };
    }
}
