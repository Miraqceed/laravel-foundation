<?php

namespace Miraqceed\Foundation\Tests\Feature\Mail;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Miraqceed\Foundation\Mail\NormalizeEmailInput;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 検証の前に、メールアドレスの欄（とその _confirmation）を半角にそろえる。入れたときだけ働く
 */
class NormalizeEmailInputTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->post('/with', fn (Request $request) => $request->all())->middleware(NormalizeEmailInput::class);
        $router->post('/without', fn (Request $request) => $request->all());
        $router->post('/register', function (Request $request) {
            $request->validate(['email' => ['required', 'email', 'confirmed']]);

            return ['email' => $request->input('email')];
        })->middleware(NormalizeEmailInput::class);
    }

    public function test_email_と入れ子と_confirmation_をそろえ_ほかの欄は変えない(): void
    {
        $this->postJson('/with', [
            'email' => 'ｔａｒｏ＠Example。CO.JP。',
            'email_confirmation' => 'ｔａｒｏ＠Example。CO.JP。',
            'rows' => [['email' => 'ｈａｎａ@example.co.jp', 'email_confirmation' => 'ｈａｎａ@example.co.jp', 'name' => 'ｈａｎａ']],
            'password' => 'ｐａｓｓ　',
            'new_email' => 'ｎｅｗ@example.co.jp',
            'note' => 'ｔａｒｏ@example.co.jp',
        ])->assertExactJson([
            'email' => 'taro@example.co.jp',
            'email_confirmation' => 'taro@example.co.jp',
            'rows' => [['email' => 'hana@example.co.jp', 'email_confirmation' => 'hana@example.co.jp', 'name' => 'ｈａｎａ']],
            'password' => 'ｐａｓｓ　',
            'new_email' => 'ｎｅｗ@example.co.jp',
            'note' => 'ｔａｒｏ@example.co.jp',
        ]);
    }

    public function test_フォームの本文も同じ(): void
    {
        $this->post('/with', ['email' => 'ｔａｒｏ@example.co.jp'])->assertJson(['email' => 'taro@example.co.jp']);
    }

    public function test_全角で両方を打っても_confirmed_と_email_の規則を通る(): void
    {
        $this->postJson('/register', ['email' => 'ｔａｒｏ@example.co.jp。', 'email_confirmation' => 'ｔａｒｏ@example.co.jp。'])
            ->assertOk()
            ->assertExactJson(['email' => 'taro@example.co.jp']);
    }

    public function test_整えた値は_email_の規則を通る(): void
    {
        foreach (['ｔａｒｏ@example.co.jp', 'taro@ｅｘａｍｐｌｅ.co.jp', 'taro＠example.co.jp', 'taro@example。co.jp', 'taro@example.co.jp。', ' ＴＡＲＯ@example.co.jp　'] as $input) {
            $email = $this->postJson('/with', ['email' => $input])->json('email');
            $this->assertTrue(Validator::make(['e' => $email], ['e' => 'email:rfc,strict'])->passes(), $input);
        }
    }

    public function test_設定の名前を足すと_その_confirmation_も整う(): void
    {
        config(['foundation.mail.input_fields' => ['email', 'new_email']]);

        $this->postJson('/with', ['new_email' => 'ｎｅｗ@example.co.jp', 'new_email_confirmation' => 'ｎｅｗ@example.co.jp'])
            ->assertExactJson(['new_email' => 'new@example.co.jp', 'new_email_confirmation' => 'new@example.co.jp']);
    }

    public function test_文字列でない値と壊れた_UTF_8_は変えない(): void
    {
        $this->post('/with', ['email' => ['ｔａｒｏ@example.co.jp']])->assertJson(['email' => ['ｔａｒｏ@example.co.jp']]);

        $request = Request::create('/with', 'POST', ['email' => "ｔａｒｏ\xff@example.co.jp"]);
        (new NormalizeEmailInput)->handle($request, fn () => null);
        $this->assertSame("ｔａｒｏ\xff@example.co.jp", $request->input('email'));
    }

    public function test_入れなければ何も変わらない(): void
    {
        $this->postJson('/without', ['email' => 'ｔａｒｏ@example.co.jp'])->assertExactJson(['email' => 'ｔａｒｏ@example.co.jp']);
    }

    public function test_設定の書き違いは止める(): void
    {
        config(['foundation.mail.input_fields' => 'email']);

        $request = Request::create('/with', 'POST', ['email' => 'ｔａｒｏ@example.co.jp']);

        $this->expectException(InvalidArgumentException::class);
        (new NormalizeEmailInput)->handle($request, fn () => null);
    }
}
