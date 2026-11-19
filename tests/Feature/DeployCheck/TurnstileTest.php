<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Miraqceed\Foundation\DeployCheck\Checks\Turnstile;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * turnstile：両方空なら skip、一方だけ・テスト用の鍵なら fail、両方入っていれば ok。値は出さない
 */
class TurnstileTest extends TestCase
{
    private const SITE = '0x4AAAAAAAsite-for-tests';

    private const SECRET = '0x4AAAAAAAsecret-for-tests-only';

    /** @return array{Status, ?string} */
    private function check(?string $site, ?string $secret): array
    {
        config(['services.turnstile.site_key' => $site, 'services.turnstile.secret_key' => $secret]);
        $result = (new Turnstile)->run();

        foreach ([self::SITE, self::SECRET, '1x00000000000000000000AA'] as $value) {
            $this->assertStringNotContainsString($value, (string) $result->detail);
        }

        return [$result->status, $result->detail];
    }

    public function test_両方空なら_skip(): void
    {
        $this->assertSame(Status::Skip, $this->check(null, null)[0]);
        $this->assertSame(Status::Skip, $this->check('', '')[0]);
    }

    public function test_一方だけなら_fail(): void
    {
        [$status, $detail] = $this->check(self::SITE, null);
        $this->assertSame(Status::Fail, $status);
        $this->assertStringContainsString('秘密の鍵', (string) $detail);

        [$status, $detail] = $this->check(null, self::SECRET);
        $this->assertSame(Status::Fail, $status);
        $this->assertStringContainsString('サイトの鍵', (string) $detail);
    }

    public function test_テスト用の鍵なら_fail(): void
    {
        foreach ([['1x00000000000000000000AA', self::SECRET], [self::SITE, '2x0000000000000000000000000000000AA'], ['3x00000000000000000000FF', '1x0000000000000000000000000000000AA']] as [$site, $secret]) {
            [$status, $detail] = $this->check($site, $secret);
            $this->assertSame(Status::Fail, $status);
            $this->assertStringContainsString('テスト用', (string) $detail);
        }
    }

    public function test_両方入っていて_テスト用でなければ_ok(): void
    {
        $this->assertSame(Status::Ok, $this->check(self::SITE, self::SECRET)[0]);
    }
}
