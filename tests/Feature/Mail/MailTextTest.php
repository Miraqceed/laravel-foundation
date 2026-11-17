<?php

namespace Miraqceed\Foundation\Tests\Feature\Mail;

use DOMDocument;
use DOMXPath;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\HtmlString;
use Miraqceed\Foundation\Mail\MailText;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\Email;

class MailTextTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'array');
        $app['config']->set('mail.from', ['address' => 'app@example.co.jp', 'name' => 'App']);
        $app['config']->set('app.url', 'https://app.example.jp');
    }

    protected function tearDown(): void
    {
        // view:cache のコンパイル済みのファイルは試しをまたいで残るので消す。掛けた全体の状態も戻す
        Artisan::call('view:clear');
        if (method_exists(Markdown::class, 'withoutSecuredEncoding')) {
            Markdown::withoutSecuredEncoding();
        }

        parent::tearDown();
    }

    /**
     * 挨拶と、行の頭に置いた文を送り、届いたメールを返す。
     */
    private function send(HtmlString $line, ?HtmlString $greeting = null): Email
    {
        $notification = new class($line, $greeting) extends Notification
        {
            public function __construct(private HtmlString $line, private ?HtmlString $greeting) {}

            /** @return list<string> */
            public function via(object $notifiable): array
            {
                return ['mail'];
            }

            public function toMail(object $notifiable): MailMessage
            {
                $message = (new MailMessage)->subject('確認')->line($this->line);

                return $this->greeting === null ? $message : $message->greeting($this->greeting);
            }
        };

        NotificationFacade::route('mail', 'someone@gmail.com')->notifyNow($notification);

        return Mail::mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    }

    /**
     * HTML の版で、` END` で終わる文を直に包む要素の名前と、その文（実体参照を戻したもの）。
     *
     * @return array{string, string}
     */
    private static function htmlLine(Email $email): array
    {
        preg_match('/<(\w+)[^>]*>([^<]*) END/', (string) $email->getHtmlBody(), $match);

        return [$match[1] ?? '', html_entity_decode($match[2] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }

    /**
     * HTML の版で、evil.example を指す属性を持つ要素と、script の要素（文字でなく、要素としてできたもの）。
     *
     * @return list<string>
     */
    private static function evilElements(Email $email): array
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$email->getHtmlBody());

        $found = [];
        foreach ((new DOMXPath($document))->query('//*[contains(@href, "evil.example") or contains(@src, "evil.example")] | //script') as $node) {
            $found[] = $node->nodeName;
        }

        return $found;
    }

    /** @return array<string, array{string}> */
    public static function texts(): array
    {
        return [
            'リンク' => ['[ここを押す](https://evil.example/x)'],
            '画像' => ['![i](https://evil.example/p.png)'],
            '山かっこのリンク' => ['<https://evil.example/a>'],
            'HTML' => ['<script>alert(1)</script><a href="https://evil.example">x</a>'],
            '見出し' => ['# 見出し'],
            '引用' => ['> 引用'],
            '箇条' => ['- 箇条'],
            '箇条（+）' => ['+ 箇条'],
            '箇条（*）' => ['* 箇条'],
            '番号' => ['1. 番号'],
            '強調' => ['a_b_ *c* **d** ~~e~~'],
            'コード' => ['`x` ``y``'],
            '字下げ' => ['    コード'],
            '表' => ['| a | b |'],
            'ふつうの名前' => ['山田 太郎'],
            '記号の入った名前' => ['山田 太郎-営業部'],
            '会社名' => ['株式会社(テスト).jp'],
            'アポストロフィと &' => ["O'Brien a&b"],
            '逆斜線' => ['a\\b'],
            '実体参照の形' => ['&amp;lt; &#91;'],
            '素の URL' => ['https://evil.example/u'],
        ];
    }

    #[DataProvider('texts')]
    public function test_HTML_の版で書いたとおりの文字になる(string $text): void
    {
        $email = $this->send(MailText::escape($text.' END'));

        // 段落の中の文字のまま（リンク・画像・見出し・引用・箇条・強調・コードにならない）
        $this->assertSame(['p', $text], self::htmlLine($email));
        $this->assertSame([], self::evilElements($email));
    }

    #[DataProvider('texts')]
    public function test_text_の版で名前がそのまま読める(string $text): void
    {
        $email = $this->send(MailText::escape($text.' END'));

        // text の版は Markdown を HTML に変えず、実体参照だけを戻す。\ も実体参照も残らない
        $this->assertStringContainsString($text.' END', (string) $email->getTextBody());
    }

    public function test_挨拶に入れても文字のまま(): void
    {
        $email = $this->send(MailText::escape('ふつうの行 END'), MailText::escape('[ここを押す](https://evil.example/x) さん'));

        $this->assertStringContainsString('[ここを押す](https://evil.example/x) さん', html_entity_decode((string) $email->getHtmlBody(), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertSame([], self::evilElements($email));
        $this->assertStringContainsString('[ここを押す](https://evil.example/x) さん', (string) $email->getTextBody());
    }

    public function test_改行で段落・参照の定義を作らない(): void
    {
        $email = $this->send(MailText::escape("一行目\n\n[x]\r\n[x]: https://evil.example/r\r> 引用 END"));

        $this->assertSame(['p', '一行目  [x] [x]: https://evil.example/r > 引用'], self::htmlLine($email));
        $this->assertSame([], self::evilElements($email));
        $this->assertStringContainsString('一行目  [x] [x]: https://evil.example/r > 引用 END', (string) $email->getTextBody());
    }

    public function test_view_cache_の後も同じ(): void
    {
        Artisan::call('view:cache');

        $email = $this->send(MailText::escape('[ここを押す](https://evil.example/x) 山田-太郎 END'));

        $this->assertSame(['p', '[ここを押す](https://evil.example/x) 山田-太郎'], self::htmlLine($email));
        $this->assertStringContainsString('[ここを押す](https://evil.example/x) 山田-太郎 END', (string) $email->getTextBody());
    }

    public function test_withSecuredEncoding_でも二重に打ち消さない(): void
    {
        if (! method_exists(Markdown::class, 'withSecuredEncoding')) {
            $this->markTestSkipped('Markdown::withSecuredEncoding() は Laravel 12.15 から');
        }

        Markdown::withSecuredEncoding();

        foreach ([false, true] as $cached) {
            if ($cached) {
                Artisan::call('view:cache');
            }

            $email = $this->send(MailText::escape("[ここを押す](https://evil.example/x) O'Brien a&b 山田-太郎 END"));

            // `&amp;#91;` や `&amp;amp;` のまま見えない
            $this->assertSame(['p', "[ここを押す](https://evil.example/x) O'Brien a&b 山田-太郎"], self::htmlLine($email));
            $this->assertStringContainsString("[ここを押す](https://evil.example/x) O'Brien a&b 山田-太郎 END", (string) $email->getTextBody());
        }
    }
}
