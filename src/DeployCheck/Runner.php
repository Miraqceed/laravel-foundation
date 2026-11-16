<?php

namespace Miraqceed\Foundation\DeployCheck;

use Illuminate\Contracts\Container\Container;
use Miraqceed\Foundation\DeployCheck\Checks\AppDebug;
use Miraqceed\Foundation\DeployCheck\Checks\AppEnv;
use Miraqceed\Foundation\DeployCheck\Checks\AppKey;
use Miraqceed\Foundation\DeployCheck\Checks\AppUrl;
use Miraqceed\Foundation\DeployCheck\Checks\AttemptsBypass;
use Miraqceed\Foundation\DeployCheck\Checks\Boost;
use Miraqceed\Foundation\DeployCheck\Checks\Drivers;
use Miraqceed\Foundation\DeployCheck\Checks\Mail;
use Miraqceed\Foundation\DeployCheck\Checks\MailViewCache;
use Miraqceed\Foundation\DeployCheck\Checks\Migrations;
use Miraqceed\Foundation\DeployCheck\Checks\SessionSecureCookie;
use Miraqceed\Foundation\DeployCheck\Checks\TrustedProxies;
use Miraqceed\Foundation\DeployCheck\Checks\ViteHot;
use Miraqceed\Foundation\DeployCheck\Checks\ViteManifest;
use Miraqceed\Foundation\DeployCheck\Checks\Writable;

/**
 * 調べを並びのとおりに走らせる：パッケージの既定の調べ、次に foundation.deploy_check.checks。
 *
 * - 調べごとに Throwable を捕まえる（作るとき・id()・label()・run() のどれでも）。その行だけを失敗にし、
 *   例外のクラス名だけを出す（文には DB のユーザー名・ホストなどが入る）。残りの調べは続ける
 * - 同じ id は後のものを失敗にする。見つからないクラス・Check でないクラスも、その行だけ失敗
 * - foundation.deploy_check.skip の id は走らせない（skip と出す）
 */
final class Runner
{
    /**
     * パッケージの既定の調べ。部品が調べを足すときはここに足す（部品を使っていなければ skip を返す）
     *
     * @var list<class-string<Check>>
     */
    public const DEFAULTS = [
        AppEnv::class,
        AppDebug::class,
        AppUrl::class,
        AppKey::class,
        Boost::class,
        SessionSecureCookie::class,
        Drivers::class,
        Mail::class,
        MailViewCache::class,
        ViteHot::class,
        ViteManifest::class,
        Writable::class,
        Migrations::class,
        AttemptsBypass::class,
        TrustedProxies::class,
    ];

    private const ID_PATTERN = '/\A[a-z][a-z0-9_]*\z/';

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<array{id: string, status: Status, label: string, detail: ?string}>
     */
    public function run(): array
    {
        $skip = config('foundation.deploy_check.skip');
        $skip = is_array($skip) ? array_values(array_filter($skip, 'is_string')) : [];

        $rows = [];
        $seen = [];
        foreach ($this->entries() as $entry) {
            $row = $this->runOne($entry, $skip);

            // 作れなかった行（id が invalid_check）は重なりを数えない
            if (! isset($row['invalid'])) {
                if (isset($seen[$row['id']])) {
                    $row = ['id' => $row['id'], 'status' => Status::Fail, 'label' => $row['label'], 'detail' => 'id が重なる（先の調べと同じ id）'];
                }
                $seen[$row['id']] = true;
            }
            unset($row['invalid']);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<mixed>
     */
    private function entries(): array
    {
        $checks = config('foundation.deploy_check.checks', []);
        if (! is_array($checks)) {
            // 設定の書き違い。並びを読めないので、1行の失敗として出す
            return [...self::DEFAULTS, null];
        }

        return [...self::DEFAULTS, ...array_values($checks)];
    }

    /**
     * @param  list<string>  $skip
     * @return array{id: string, status: Status, label: string, detail: ?string, invalid?: true}
     */
    private function runOne(mixed $entry, array $skip): array
    {
        $invalid = fn (string $id, string $detail): array => ['id' => $id, 'status' => Status::Fail, 'label' => '調べを作れない', 'detail' => $detail, 'invalid' => true];

        if (! is_string($entry) || $entry === '') {
            return $invalid('invalid_check', 'foundation.deploy_check.checks にクラス名でない値がある');
        }
        if (! class_exists($entry)) {
            return $invalid('invalid_check', "クラスが見つからない: {$entry}");
        }
        if (! is_subclass_of($entry, Check::class)) {
            return $invalid('invalid_check', "Check を実装していない: {$entry}");
        }

        try {
            /** @var Check $check */
            $check = $this->container->make($entry);
            $id = $check->id();
            $label = $check->label();
        } catch (\Throwable $e) {
            return $invalid('invalid_check', "{$entry}: ".class_basename($e));
        }

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            return $invalid('invalid_check', "id の形が違う（英小文字・数字・_）: {$entry}");
        }

        if (in_array($id, $skip, true)) {
            return ['id' => $id, 'status' => Status::Skip, 'label' => $label, 'detail' => 'foundation.deploy_check.skip で外した'];
        }

        try {
            $result = $check->run();
        } catch (\Throwable $e) {
            return ['id' => $id, 'status' => Status::Fail, 'label' => $label, 'detail' => class_basename($e)];
        }

        return ['id' => $id, 'status' => $result->status, 'label' => $label, 'detail' => $result->detail];
    }
}
