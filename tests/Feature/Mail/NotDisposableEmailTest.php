<?php

namespace Miraqceed\Foundation\Tests\Feature\Mail;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use LogicException;
use Miraqceed\Foundation\Mail\DisposableEmailDomains;
use Miraqceed\Foundation\Mail\NotDisposableEmail;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 使い捨てのメール：一覧はアプリが置く（試しの中で小さな一覧を作る）。1回だけ読む・下位のドメイン・そろえ・# の行
 */
class NotDisposableEmailTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/foundation-disposable-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir);
        config(['foundation.mail.disposable_domains' => $this->writeList("# 作り物の一覧\n\nthrowaway.example\n  Burner-Mail.TEST.  \n.trash.invalid\nexample-disposable.jp\n")]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    private function writeList(string $contents, string $name = 'list.txt'): string
    {
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    private function domains(): DisposableEmailDomains
    {
        return $this->app->make(DisposableEmailDomains::class);
    }

    public function test_一覧のドメインと下位のドメインが当たる(): void
    {
        foreach (['a@throwaway.example', 'a@mx.throwaway.example', 'a@burner-mail.test', 'a@trash.invalid', 'a@x.y.example-disposable.jp'] as $email) {
            $this->assertTrue($this->domains()->contains($email), $email);
        }
        foreach (['a@example', 'a@notthrowaway.example', 'a@throwaway.example.jp', 'a@company.jp', 'throwaway.example', 'a@'] as $email) {
            $this->assertFalse($this->domains()->contains($email), $email);
        }
    }

    public function test_全角_句点_末尾の点_大文字をそろえてから比べる(): void
    {
        foreach (['ａ＠ｔｈｒｏｗａｗａｙ．ｅｘａｍｐｌｅ', 'a@throwaway。example', 'a@Throwaway.Example.', ' a@throwaway.example　'] as $email) {
            $this->assertTrue($this->domains()->contains($email), $email);
        }
    }

    public function test_トップレベルだけの行では当てない(): void
    {
        config(['foundation.mail.disposable_domains' => $this->writeList("jp\nexample\n", 'tld.txt')]);

        $this->assertFalse($this->domains()->contains('a@company.jp'));
    }

    public function test_日本語のドメインは_xn_の形で比べる(): void
    {
        config(['foundation.mail.disposable_domains' => $this->writeList("例え.jp\n", 'idn.txt')]);

        $this->assertTrue($this->domains()->contains('a@例え.jp'));
        $this->assertTrue($this->domains()->contains('a@xn--r8jz45g.jp'));
    }

    public function test_1つのプロセスで1回だけ読み_道が変われば読み直す(): void
    {
        $domains = $this->domains();
        $this->assertTrue($domains->contains('a@throwaway.example'));
        $this->assertSame($domains, $this->domains());

        // 読んだ後にファイルを書き換えても、同じ道なら読み直さない
        file_put_contents((string) config('foundation.mail.disposable_domains'), "other.example\n");
        $this->assertTrue($this->domains()->contains('a@throwaway.example'));
        $this->assertFalse($this->domains()->contains('a@other.example'));

        config(['foundation.mail.disposable_domains' => $this->writeList("other.example\n", 'other.txt')]);
        $this->assertTrue($this->domains()->contains('a@other.example'));
    }

    public function test_規則は使い捨てを断り_文は1つ(): void
    {
        app('translator')->setLocale('ja');

        $validator = Validator::make(['email' => 'a@mx.throwaway.example'], ['email' => [new NotDisposableEmail]]);
        $this->assertTrue($validator->fails());
        $this->assertSame('使い捨てのメールアドレスは使えません。普段お使いのメールアドレスを入れてください。', $validator->errors()->first('email'));

        $this->assertTrue(Validator::make(['email' => 'a@company.jp'], ['email' => [new NotDisposableEmail]])->passes());
        $this->assertTrue(Validator::make(['email' => ['a@throwaway.example']], ['email' => [new NotDisposableEmail]])->passes());
    }

    public function test_一覧の設定がなければ止める(): void
    {
        config(['foundation.mail.disposable_domains' => null]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('foundation.mail.disposable_domains');
        Validator::make(['email' => 'a@company.jp'], ['email' => [new NotDisposableEmail]])->passes();
    }

    public function test_一覧が読めなければ止める(): void
    {
        config(['foundation.mail.disposable_domains' => $this->dir.'/missing.txt']);

        $this->expectException(LogicException::class);
        $this->domains()->contains('a@company.jp');
    }
}
