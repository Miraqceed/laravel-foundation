<?php

namespace Miraqceed\Foundation\Tests\Unit\Redaction;

use Miraqceed\Foundation\Redaction\Finding;
use Miraqceed\Foundation\Redaction\Redactor;
use Miraqceed\Foundation\Tests\Support\FakeSecrets;
use PHPUnit\Framework\TestCase;

/**
 * 伏せてから字数で切る。切ってから伏せると、切れ目で秘密が欠片になって形に合わずに残る
 */
class RedactorTruncateTest extends TestCase
{
    public function test_redacts_before_cutting(): void
    {
        $result = (new Redactor)->truncate('連絡は taro@example.com へ。'.str_repeat('あ', 100), 20);

        $this->assertSame('連絡は [REDACTED] へ。'.str_repeat('あ', 3), $result->value);
        $this->assertEquals([new Finding('email', '')], $result->findings);
    }

    public function test_cuts_by_characters_not_bytes(): void
    {
        $result = (new Redactor)->truncate(str_repeat('秘', 30), 10);

        $this->assertSame(str_repeat('秘', 10), $result->value);
    }

    public function test_cuts_text_far_longer_than_the_limit_to_the_limit(): void
    {
        $result = (new Redactor)->truncate(str_repeat('あ', 50000), 100);

        $this->assertSame(100, mb_strlen($result->value));
    }

    /**
     * 荒い境目（上限 ＋ 8,000字）にまたがる秘密は、切れて形に合わずに伏せられない。手前の秘密が伏せて縮むと、
     * その欠片が上限の内に入ってきうる。欠片は伏せた後の文の末尾から捨てる
     */
    public function test_leaves_no_fragment_of_a_secret_cut_at_the_rough_boundary_even_when_earlier_secrets_shrink(): void
    {
        $limit = 100;
        $rough = $limit + 8000;
        $key = FakeSecrets::privateKey();
        // 秘密鍵を荒い境目の 40字手前から始め、境目で切れるようにする（欠片に BEGIN … PRIVATE KEY と鍵の中身の頭が入る）
        $keyStart = $rough - 40;
        // 手前を2つの長いトークンで埋める。伏せると数十字に縮み、欠片が上限の内に入ってくる
        $tokenLength = intdiv($keyStart - 2, 2);
        $token = 'gh'.'p_'.str_repeat('F', $tokenLength - 4);
        $head = $token.' '.$token.' ';
        $text = $head.str_repeat('あ', $keyStart - mb_strlen($head)).$key.str_repeat('い', 100);
        $this->assertSame($keyStart, mb_strpos($text, '-----BEGIN'));

        $result = (new Redactor)->truncate($text, $limit);

        $this->assertStringNotContainsString('FAKEkey', $result->value);
        $this->assertStringNotContainsString('PRIVATE', $result->value);
        $this->assertLessThanOrEqual($limit, mb_strlen($result->value));
    }

    public function test_redacts_text_that_matches_only_after_cutting(): void
    {
        // 03-1234-56789 は最後が5桁で電話番号の形に合わない。最後の1字で切れると 03-1234-5678 になり形に合う
        $text = '番号 03-1234-56789';

        $result = (new Redactor)->truncate($text, mb_strlen($text) - 1);

        $this->assertStringNotContainsString('03-1234-5678', $result->value);
        $this->assertEquals([new Finding('phone', '')], $result->findings);
    }

    public function test_output_never_exceeds_the_limit(): void
    {
        $texts = [
            'メール a@b.zz と a@b.zz を見た',
            '番号 03-1234-56789 と 03-1234-56789',
            'x a@b.zz',
            // a@b.zz9 はメールアドレスの形に合わない。a@b.zz で切れると形に合い、6字が印（10字）に替わって伸びる
            'x a@b.zz9',
            'メール a@b.zz9 です',
        ];
        $redactor = new Redactor;

        foreach ($texts as $text) {
            for ($limit = 0; $limit <= 30; $limit++) {
                $value = $redactor->truncate($text, $limit)->value;
                $this->assertLessThanOrEqual($limit, mb_strlen($value), "上限 {$limit}: {$value}");
            }
        }
    }

    public function test_a_marker_cut_at_the_limit_is_not_counted_again(): void
    {
        $result = (new Redactor)->truncate('password=FakePass1234 です', 18);

        $this->assertSame('password=[REDACTED', $result->value);
        $this->assertEquals([new Finding('assignment', '')], $result->findings);
    }

    public function test_negative_limit_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Redactor)->truncate('文', -1);
    }
}
