<?php

namespace Miraqceed\Foundation\Tests\Feature\Japan;

use Illuminate\Support\Facades\Validator;
use Miraqceed\Foundation\Japan\Rules\JapanesePhoneNumber;
use Miraqceed\Foundation\Japan\Rules\JapanesePostalCode;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 電話番号・郵便番号の検証の規則。文は理由（shape・grouping）で分け、日本語と英語で欄の名前が入る
 */
class RulesTest extends TestCase
{
    private function validate(object $rule, mixed $value, string $locale = 'ja'): \Illuminate\Validation\Validator
    {
        $this->app->setLocale($locale);

        return Validator::make(['field' => $value], ['field' => [$rule]], [], ['field' => $locale === 'ja' ? '電話番号' : 'phone']);
    }

    public function test_区切れる電話番号は通す(): void
    {
        $this->assertTrue($this->validate(new JapanesePhoneNumber, '０３（１２３４）５６７８')->passes());
        $this->assertTrue($this->validate(new JapanesePhoneNumber, '0120-98-8372')->passes());
    }

    public function test_番号の形でない電話番号は_形の文で断る(): void
    {
        $this->assertSame(
            '電話番号には、0 から始まる10桁か11桁の電話番号を、数字とハイフンで入れてください。',
            $this->validate(new JapanesePhoneNumber, '03-1234-5678 内線12')->errors()->first('field'),
        );
        $this->assertSame(
            'The phone must be a Japanese phone number of 10 or 11 digits starting with 0, written with digits and hyphens.',
            $this->validate(new JapanesePhoneNumber, '12345', 'en')->errors()->first('field'),
        );
    }

    public function test_区切りが決まらない電話番号は_区切りの文で断る(): void
    {
        $this->assertSame(
            '電話番号の区切りが分かりません。市外局番・市内局番・番号をハイフンで区切って入れてください。',
            $this->validate(new JapanesePhoneNumber, '0980123456')->errors()->first('field'),
        );
        $this->assertSame(
            'We could not tell how to group the phone. Please separate the area code, local exchange and number with hyphens.',
            $this->validate(new JapanesePhoneNumber, '0980123456', 'en')->errors()->first('field'),
        );
    }

    public function test_郵便番号の規則(): void
    {
        $this->assertTrue($this->validate(new JapanesePostalCode, '〒１００－０００１')->passes());
        $this->assertSame('電話番号には、7桁の郵便番号を入れてください。', $this->validate(new JapanesePostalCode, '100-001')->errors()->first('field'));
        $this->assertSame('The phone must be a 7-digit postal code.', $this->validate(new JapanesePostalCode, '100-001', 'en')->errors()->first('field'));
    }

    public function test_文字列でない値は通す(): void
    {
        foreach ([new JapanesePhoneNumber, new JapanesePostalCode] as $rule) {
            $this->assertTrue($this->validate($rule, null)->passes());
            $this->assertTrue($this->validate($rule, ['0312345678'])->passes());
            $this->assertTrue($this->validate($rule, 123)->passes());
        }
    }
}
