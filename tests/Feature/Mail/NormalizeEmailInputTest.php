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
            'email' => 'ｔａｒｏ＠Example。COM。',
            'email_confirmation' => 'ｔａｒｏ＠Example。COM。',
            'rows' => [['email' => 'ｈａｎａ@example.com', 'email_confirmation' => 'ｈａｎａ@example.com', 'name' => 'ｈａｎａ']],
            'password' => 'ｐａｓｓ　',
            'new_email' => 'ｎｅｗ@example.com',
            'note' => 'ｔａｒｏ@example.com',
        ])->assertExactJson([
            'email' => 'taro@example.com',
            'email_confirmation' => 'taro@example.com',
            'rows' => [['email' => 'hana@example.com', 'email_confirmation' => 'hana@example.com', 'name' => 'ｈａｎａ']],
            'password' => 'ｐａｓｓ　',
            'new_email' => 'ｎｅｗ@example.com',
            'note' => 'ｔａｒｏ@example.com',
        ]);
    }

    public function test_フォームの本文も同じ(): void
    {
        $this->post('/with', ['email' => 'ｔａｒｏ@example.com'])->assertJson(['email' => 'taro@example.com']);
    }

    public function test_全角で両方を打っても_confirmed_と_email_の規則を通る(): void
    {
        $this->postJson('/register', ['email' => 'ｔａｒｏ@example.com。', 'email_confirmation' => 'ｔａｒｏ@example.com。'])
            ->assertOk()
            ->assertExactJson(['email' => 'taro@example.com']);
    }

    public function test_整えた値は_email_の規則を通る(): void
    {
        foreach (['ｔａｒｏ@example.com', 'taro@ｅｘａｍｐｌｅ.com', 'taro＠example.com', 'taro@example。com', 'taro@example.com。', ' ＴＡＲＯ@example.com　'] as $input) {
            $email = $this->postJson('/with', ['email' => $input])->json('email');
            $this->assertTrue(Validator::make(['e' => $email], ['e' => 'email:rfc,strict'])->passes(), $input);
        }
    }

    public function test_設定の名前を足すと_その_confirmation_も整う(): void
    {
        config(['foundation.mail.input_fields' => ['email', 'new_email']]);

        $this->postJson('/with', ['new_email' => 'ｎｅｗ@example.com', 'new_email_confirmation' => 'ｎｅｗ@example.com'])
            ->assertExactJson(['new_email' => 'new@example.com', 'new_email_confirmation' => 'new@example.com']);
    }

    public function test_文字列でない値と壊れた_UTF_8_は変えない(): void
    {
        $this->post('/with', ['email' => ['ｔａｒｏ@example.com']])->assertJson(['email' => ['ｔａｒｏ@example.com']]);

        $request = Request::create('/with', 'POST', ['email' => "ｔａｒｏ\xff@example.com"]);
        (new NormalizeEmailInput)->handle($request, fn () => null);
        $this->assertSame("ｔａｒｏ\xff@example.com", $request->input('email'));
    }

    public function test_入れなければ何も変わらない(): void
    {
        $this->postJson('/without', ['email' => 'ｔａｒｏ@example.com'])->assertExactJson(['email' => 'ｔａｒｏ@example.com']);
    }

    public function test_設定の書き違いは止める(): void
    {
        config(['foundation.mail.input_fields' => 'email']);

        $request = Request::create('/with', 'POST', ['email' => 'ｔａｒｏ@example.com']);

        $this->expectException(InvalidArgumentException::class);
        (new NormalizeEmailInput)->handle($request, fn () => null);
    }
}
