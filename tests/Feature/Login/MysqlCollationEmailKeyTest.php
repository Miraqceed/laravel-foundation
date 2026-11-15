<?php

namespace Miraqceed\Foundation\Tests\Feature\Login;

use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Login\MysqlCollationEmailKey;
use Miraqceed\Foundation\Tests\TestCase;

class MysqlCollationEmailKeyTest extends TestCase
{
    private const TABLE = 'foundation_email_key_probe';

    protected function setUp(): void
    {
        parent::setUp();

        if (env('DB_CONNECTION', 'sqlite') !== 'mysql') {
            $this->markTestSkipped('MySQL の照合順序の重みを使うので、DB_CONNECTION=mysql のときだけ流す');
        }

        Schema::dropIfExists(self::TABLE);
        Schema::create(self::TABLE, function ($table) {
            $table->id();
            $table->string('email')->collation('utf8mb4_unicode_ci');
        });
    }

    protected function tearDown(): void
    {
        if (env('DB_CONNECTION', 'sqlite') === 'mysql') {
            Schema::dropIfExists(self::TABLE);
        }

        parent::tearDown();
    }

    public function test_mysql_collation_key_matches_case_and_width(): void
    {
        $key = new MysqlCollationEmailKey(self::TABLE, 'email');

        $base = $key->key('a@example.com');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $base);
        $this->assertSame($base, $key->key('A@Example.com'));
        $this->assertSame($base, $key->key('ａ@example.com'));
        $this->assertSame($base, $key->key('a@example.com  ')); // PAD SPACE
        $this->assertNotSame($base, $key->key('b@example.com'));
    }

    public function test_throws_when_weight_cannot_be_computed(): void
    {
        $key = new MysqlCollationEmailKey('foundation_no_such_table', 'email');

        $this->expectException(\RuntimeException::class);
        $key->key('a@example.com');
    }
}
