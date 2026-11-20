<?php

namespace Miraqceed\Foundation\DeployCheck\Checks;

use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Result;
use Miraqceed\Foundation\Japan\PostalAddressBook;
use Throwable;

/**
 * 郵便番号の一覧（foundation:postal-codes が作る）が古くないか。日本郵便の更新日から3か月を過ぎれば later
 * （古くても引けるので止めない。--strict で失敗）。置き場所に source.json がなければ skip（使っていない）。読めなければ FAIL
 */
final class PostalCodes implements Check
{
    public function id(): string
    {
        return 'postal_codes';
    }

    public function label(): string
    {
        return '郵便番号の一覧が新しい（日本郵便の更新日から'.PostalAddressBook::STALE_MONTHS.'か月以内。php artisan foundation:postal-codes）';
    }

    public function run(): Result
    {
        try {
            $book = new PostalAddressBook;
            $updated = $book->updatedOn();
            if ($updated === null) {
                return Result::skip('郵便番号の一覧を置いていない');
            }
            $stale = $book->isStale(now());
        } catch (Throwable $e) {
            return Result::fail('一覧の source.json を読めない（'.$e::class.'）。php artisan foundation:postal-codes で作り直す');
        }

        if ($stale) {
            return Result::later("日本郵便の更新日 {$updated} から".PostalAddressBook::STALE_MONTHS.'か月を過ぎた。php artisan foundation:postal-codes で作り直す');
        }

        return Result::ok("日本郵便の更新日 {$updated}");
    }
}
