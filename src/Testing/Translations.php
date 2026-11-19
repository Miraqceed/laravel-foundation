<?php

namespace Miraqceed\Foundation\Testing;

use Illuminate\Contracts\Support\MessageBag as MessageBagContract;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * 試しの助け：訳し忘れを見つける。本番のコードからは呼ばない。
 *
 * - missing()：基準の英語（Laravel の lang/en ＋ アプリの lang/en）にあって、アプリの lang/{$locale} にない鍵。
 *   文がない鍵は予備の言語（fallback_locale）の英語の文になり、「The メールアドレス field is required.」と混ざる
 * - assertNoEnglish()：実際に出た誤りの文に、英語の語が混ざっていない。欄の名前の置き忘れ（current password）も見つかる
 */
final class Translations
{
    /** 欄ごとの文と欄の名前は、アプリが自分の欄に合わせて書く。英語の例（attribute-name）と比べない */
    private const APP_SPECIFIC = ['validation.custom', 'validation.attributes'];

    /** 文に入ることの多い、ファイルの拡張子（mimes・extensions の :values）。英語の語として数えない */
    private const DEFAULT_ALLOW = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'svg', 'pdf', 'csv', 'txt', 'zip'];

    /**
     * 基準の英語にあって、$locale にない鍵（validation.required・validation.max.string の形）。
     * $namespace を渡すと、その名前空間の文（パッケージの規則の文 foundation:: など）の en との差を返す
     *
     * @param  list<string>  $groups
     * @return list<string>
     */
    public static function missing(string $locale = 'ja', array $groups = ['validation', 'auth', 'passwords', 'pagination'], ?string $namespace = null): array
    {
        // 翻訳を作ってから読む。パッケージの名前空間（loadTranslationsFrom）は、翻訳が作られたときに読み手へ足される
        /** @var Loader $loader */
        $loader = app('translator')->getLoader();
        $prefix = $namespace !== null ? $namespace.'::' : '';
        $missing = [];

        foreach ($groups as $group) {
            $base = self::keys($loader->load('en', $group, $namespace ?? '*'), $group);
            $translated = self::keys($loader->load($locale, $group, $namespace ?? '*'), $group);

            foreach (array_diff($base, $translated) as $key) {
                $missing[] = $prefix.$key;
            }
        }

        return $missing;
    }

    /**
     * 誤りの文に、英語の語（前後が英字でない、小文字の英字2字以上の続き）がない。
     * URL・Base64 のように大文字で始まる語は数えない。$allow で許す語を足せる（既定でファイルの拡張子を許す）。
     * TestResponse なら、セッションの誤り（すべての袋）と JSON の errors を見る。文が1つもなければ失敗する（何も確かめていないため）
     *
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>|array<array-key, mixed>|MessageBagContract  $errors
     * @param  list<string>  $allow
     */
    public static function assertNoEnglish(TestResponse|array|MessageBagContract $errors, array $allow = []): void
    {
        $messages = self::messages($errors);
        Assert::assertNotSame([], $messages, '誤りの文が見つかりません（確かめる文がない）');

        $allowed = array_merge(self::DEFAULT_ALLOW, $allow);
        $mixed = [];

        foreach ($messages as $message) {
            preg_match_all('/(?<![A-Za-z])[a-z]{2,}(?![A-Za-z])/', $message, $matches);
            $words = array_values(array_diff($matches[0], $allowed));
            if ($words !== []) {
                $mixed[] = $message.'（'.implode(', ', array_unique($words)).'）';
            }
        }

        Assert::assertSame([], $mixed, "誤りの文に英語の語が混ざっています：\n".implode("\n", $mixed));
    }

    /**
     * 入れ子の鍵を、点でつないだ1列にする（max.string）。欄ごとの文と欄の名前は除く
     *
     * @param  array<array-key, mixed>  $lines
     * @return list<string>
     */
    private static function keys(array $lines, string $prefix): array
    {
        if (in_array($prefix, self::APP_SPECIFIC, true)) {
            return [];
        }

        $keys = [];
        foreach ($lines as $key => $value) {
            if (is_array($value)) {
                array_push($keys, ...self::keys($value, $prefix.'.'.$key));
            } else {
                $keys[] = $prefix.'.'.$key;
            }
        }

        return $keys;
    }

    /**
     * @param  TestResponse<\Symfony\Component\HttpFoundation\Response>|array<array-key, mixed>|MessageBagContract  $errors
     * @return list<string>
     */
    private static function messages(TestResponse|array|MessageBagContract $errors): array
    {
        if ($errors instanceof MessageBagContract) {
            return array_values($errors->all());
        }

        if (is_array($errors)) {
            $messages = [];
            array_walk_recursive($errors, function (mixed $value) use (&$messages): void {
                if (is_string($value)) {
                    $messages[] = $value;
                }
            });

            return $messages;
        }

        $messages = [];

        $bags = app('session.store')->get('errors');
        if ($bags instanceof ViewErrorBag) {
            foreach ($bags->getBags() as $bag) {
                array_push($messages, ...array_values($bag->all()));
            }
        }

        if (str_contains((string) $errors->headers->get('Content-Type'), 'json')) {
            $json = $errors->json('errors');
            if (is_array($json)) {
                array_push($messages, ...self::messages($json));
            }
        }

        return $messages;
    }
}
