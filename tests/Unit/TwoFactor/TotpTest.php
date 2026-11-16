<?php

namespace Miraqceed\Foundation\Tests\Unit\TwoFactor;

use Miraqceed\Foundation\TwoFactor\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use PragmaRX\Google2FA\Google2FA;

/**
 * 認証アプリの確認コード（RFC 6238 の TOTP。HMAC-SHA1・6桁・30秒の刻み）の芯
 */
class TotpTest extends TestCase
{
    /** RFC 6238 の付録 B の秘密の鍵（ASCII の 12345678901234567890）を Base32 にしたもの */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /** 作り物の秘密の鍵（20 バイトを Base32 にしたもの） */
    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    /** 刻みのちょうど中ほどの時刻 */
    private const TIME = 1795136415;

    /** @return array<string, array{0: int, 1: string}> */
    public static function rfcVectors(): array
    {
        // 付録 B の SHA1 の 8 桁の値の下 6 桁
        return [
            '59' => [59, '287082'],
            '1111111109' => [1111111109, '081804'],
            '1111111111' => [1111111111, '050471'],
            '1234567890' => [1234567890, '005924'],
            '2000000000' => [2000000000, '279037'],
            '20000000000' => [20000000000, '353130'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_matches_rfc_6238_vectors(int $time, string $code): void
    {
        $this->assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
        $this->assertSame($code, Totp::codeAt(self::RFC_SECRET, $time));
    }

    public function test_matches_google2fa(): void
    {
        if (! class_exists(Google2FA::class)) {
            $this->markTestSkipped('google2fa（dev の依存）がない');
        }
        $google2fa = new Google2FA;

        foreach ([Totp::generateSecret(), Totp::generateSecret(), self::SECRET] as $secret) {
            foreach ([59, self::TIME, 2000000000] as $time) {
                $this->assertSame(
                    $google2fa->oathTotp($secret, intdiv($time, 30)),
                    Totp::codeAt($secret, $time),
                );
            }
        }
    }

    public function test_accepts_one_step_either_way_and_refuses_two_steps_away(): void
    {
        $step = intdiv(self::TIME, 30);
        $codeOf = fn (int $offset) => Totp::codeAt(self::SECRET, self::TIME + 30 * $offset);
        // 前提：2 刻み離れたコードが、たまたま前後 1 刻みのコードと同じでない
        $this->assertCount(5, array_unique([$codeOf(-2), $codeOf(-1), $codeOf(0), $codeOf(1), $codeOf(2)]));

        $this->assertSame($step, Totp::verify(self::SECRET, $codeOf(0), self::TIME, null));
        $this->assertSame($step - 1, Totp::verify(self::SECRET, $codeOf(-1), self::TIME, null));
        $this->assertSame($step + 1, Totp::verify(self::SECRET, $codeOf(1), self::TIME, null));
        $this->assertNull(Totp::verify(self::SECRET, $codeOf(-2), self::TIME, null));
        $this->assertNull(Totp::verify(self::SECRET, $codeOf(2), self::TIME, null));
    }

    public function test_window_can_be_widened(): void
    {
        $step = intdiv(self::TIME, 30);
        $code = Totp::codeAt(self::SECRET, self::TIME + 60);

        $this->assertNull(Totp::verify(self::SECRET, $code, self::TIME, null, window: 1));
        $this->assertSame($step + 2, Totp::verify(self::SECRET, $code, self::TIME, null, window: 2));
    }

    public function test_refuses_steps_up_to_the_last_accepted_step(): void
    {
        $step = intdiv(self::TIME, 30);
        $code = Totp::codeAt(self::SECRET, self::TIME);

        $this->assertNull(Totp::verify(self::SECRET, $code, self::TIME, $step));
        $this->assertNull(Totp::verify(self::SECRET, $code, self::TIME, $step + 1));
        $this->assertNull(Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, self::TIME - 30), self::TIME, $step - 1));
    }

    public function test_accepts_a_step_after_the_last_accepted_step(): void
    {
        $step = intdiv(self::TIME, 30);

        $this->assertSame($step, Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, self::TIME), self::TIME, $step - 1));
        $this->assertSame($step + 1, Totp::verify(self::SECRET, Totp::codeAt(self::SECRET, self::TIME + 30), self::TIME, $step));
    }

    /** @return array<string, array{0: string, 1: string|null}> 入力・読んだ後の6桁（読めなければ null） */
    public static function codeInputs(): array
    {
        return [
            'plain' => ['123456', '123456'],
            'spaces' => [' 123 456 ', '123456'],
            'full-width spaces' => ["123\u{3000}456", '123456'],
            'full-width digits' => ['１２３４５６', '123456'],
            'mixed width' => ['１２３ 456', '123456'],
            // 除くのは空白だけ。改行は除かない（形の確かめの \z が、後ろの改行1つを通さないことの確かめ）
            'trailing newline' => ["123456\n", null],
            'five digits' => ['12345', null],
            'seven digits' => ['1234567', null],
            'letter' => ['12345a', null],
            'empty' => ['', null],
            'arabic-indic digits' => ['١٢٣٤٥٦', null],
            'tab' => ["123\t456", '123456'],
        ];
    }

    #[DataProvider('codeInputs')]
    public function test_normalizes_the_typed_code(string $input, ?string $expected): void
    {
        $this->assertSame($expected, Totp::normalizeCode($input));
    }

    public function test_verify_reads_the_typed_code(): void
    {
        $step = intdiv(self::TIME, 30);
        $code = Totp::codeAt(self::SECRET, self::TIME);
        $fullWidth = strtr($code, ['0' => '０', '1' => '１', '2' => '２', '3' => '３', '4' => '４', '5' => '５', '6' => '６', '7' => '７', '8' => '８', '9' => '９']);

        $this->assertSame($step, Totp::verify(self::SECRET, substr($code, 0, 3).' '.substr($code, 3), self::TIME, null));
        $this->assertSame($step, Totp::verify(self::SECRET, $fullWidth, self::TIME, null));
        $this->assertNull(Totp::verify(self::SECRET, $code.'0', self::TIME, null));
    }

    public function test_base32_round_trips_and_refuses_unknown_characters(): void
    {
        // RFC 4648 の 10 節の例（= の詰め物は付けない）
        $this->assertSame('', Totp::base32Encode(''));
        $this->assertSame('MY', Totp::base32Encode('f'));
        $this->assertSame('MZXW6YTBOI', Totp::base32Encode('foobar'));
        $this->assertSame('foobar', Totp::base32Decode('MZXW6YTBOI'));
        $this->assertSame('foobar', Totp::base32Decode('mzxw6ytboi======'));
        foreach (range(1, 25) as $length) {
            $bytes = random_bytes($length);
            $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        }

        $this->expectException(\InvalidArgumentException::class);
        Totp::base32Decode('MZXW6YTB01');
    }

    public function test_secret_has_160_bits_and_differs_each_time(): void
    {
        $first = Totp::generateSecret();

        $this->assertSame(20, strlen(Totp::base32Decode($first)));
        $this->assertSame(32, strlen($first));
        $this->assertNotSame($first, Totp::generateSecret());
    }

    public function test_uri_encodes_issuer_and_account(): void
    {
        $uri = Totp::uri('架空:アプリ & Co', 'a b@example.test', self::SECRET);

        $parts = parse_url($uri);
        parse_str($parts['query'], $query);
        $this->assertSame('otpauth', $parts['scheme']);
        $this->assertSame('totp', $parts['host']);
        // ラベルは「issuer:account」。それぞれを URL エンコードし、間の : だけを残す
        [$issuer, $account] = explode(':', ltrim($parts['path'], '/'));
        $this->assertSame('架空:アプリ & Co', rawurldecode($issuer));
        $this->assertSame('a b@example.test', rawurldecode($account));
        $this->assertSame(self::SECRET, $query['secret']);
        $this->assertSame('架空:アプリ & Co', $query['issuer']);
        $this->assertSame(['SHA1', '6', '30'], [$query['algorithm'], $query['digits'], $query['period']]);
    }
}
