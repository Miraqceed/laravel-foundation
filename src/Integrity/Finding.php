<?php

namespace Miraqceed\Foundation\Integrity;

/** 見つけた違いの種類。値は出力の印（変えない。台本が読む） */
enum Finding: string
{
    case Changed = 'changed';
    case Added = 'added';
    case Removed = 'removed';
    case Unreadable = 'unreadable';
    case NotRegular = 'not-regular';
    case PublicPhp = 'public-php';
    case ServedPhp = 'served-php';
    case ServedConfig = 'served-config';
    case ServedLink = 'served-link';
    case ServedHardLink = 'served-hardlink';
    case UnlistedLink = 'unlisted-link';
    case AcmeFile = 'acme-file';
    case StoragePhp = 'storage-php';
    case ViewWithoutSource = 'view-without-source';
    case ViewName = 'view-name';
    case ViewContent = 'view-content';
    case ViewStray = 'view-stray';
    case MaintenanceEntry = 'maintenance-entry';
    case MaintenanceData = 'maintenance-data';
    case InMaintenance = 'in-maintenance';

    public function label(): string
    {
        return match ($this) {
            self::Changed => '一覧と中身が違う',
            self::Added => '一覧にない',
            self::Removed => '一覧にあるのに、ない',
            self::Unreadable => '読めない',
            self::NotRegular => 'ふつうのファイルでない（名前つきのパイプなど）',
            self::PublicPhp => 'public の中の、PHP として動く名前',
            self::ServedPhp => '公開される変わる場所の、PHP として動く名前',
            self::ServedConfig => '公開される変わる場所の、.htaccess・.user.ini',
            self::ServedLink => '公開される変わる場所の、リンク',
            self::ServedHardLink => '公開される変わる場所の、ハードリンク',
            self::UnlistedLink => 'ハッシュの一覧で比べていない所（項目が多すぎるフォルダーなど）の、リンク',
            self::AcmeFile => 'SSL の確認用のフォルダーの、確認用でないもの',
            self::StoragePhp => 'storage の中の、PHP として動く名前',
            self::ViewWithoutSource => '元の Blade が見つからない、コンパイルした画面',
            self::ViewName => '名前が元の Blade と合わない、コンパイルした画面',
            self::ViewContent => '中身が元の Blade と合わない、コンパイルした画面',
            self::ViewStray => '画面のフォルダーにあるべきでないもの',
            self::MaintenanceEntry => 'Laravel の雛形と違う、メンテナンスの入口',
            self::MaintenanceData => '許していない形の、メンテナンスの設定',
            self::InMaintenance => 'メンテナンス中のまま',
        };
    }
}
