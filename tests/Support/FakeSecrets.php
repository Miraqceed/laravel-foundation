<?php

namespace Miraqceed\Foundation\Tests\Support;

/**
 * 伏せる部品の試しに使う、作り物の秘密。
 * 本物と同じ形の鍵・トークンは、倉庫の push の守りや秘密の走査に掛からないよう、文字列をつないで作る（ファイルに形のまま書かない）
 */
final class FakeSecrets
{
    public static function privateKey(): string
    {
        $begin = '-----BEGIN '.'RSA PRIVATE KEY-----';
        $end = '-----END '.'RSA PRIVATE KEY-----';

        return $begin."\nFAKEkeyFAKEkeyFAKEkey\nNOTarealKEYatALL\n".$end;
    }

    public static function awsKey(): string
    {
        return 'AK'.'IA'.'ABCDEFGHIJKLMNOP';
    }

    public static function githubToken(): string
    {
        return 'gh'.'p_'.'FAKEfakeFAKEfakeFAKEfakeFAKEfake1234';
    }

    public static function githubPat(): string
    {
        return 'github'.'_pat_'.'FAKE_fake_FAKE_fake_1234';
    }

    public static function anthropicKey(): string
    {
        return 'sk-'.'ant-'.'FAKEfakeFAKEfakeFAKE01';
    }

    public static function apiKey(): string
    {
        return 'sk-'.'FAKEfakeFAKEfakeFAKE01';
    }

    public static function slackToken(): string
    {
        return 'xo'.'xb-'.'FAKE-fake-1234567';
    }

    public static function jwt(): string
    {
        return 'eyJ'.'hbGciOiJIUzI1NiJ9.'.'eyJzdWIiOiJ0YXJvIn0.'.'FAKEsignatureFAKE';
    }
}
