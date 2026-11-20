<?php

namespace Miraqceed\Foundation\Tests\Feature\Testing;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Testing\RawIp;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * RawIp::assertNotStored()：試しの DB の文字の列に、IP の書き方のどれかが残っていれば落ちる。値は出さない
 */
class RawIpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('raw_ip_probes');
        Schema::create('raw_ip_probes', function (Blueprint $table) {
            $table->id();
            $table->string('label')->nullable();
            $table->text('note')->nullable();
            $table->integer('count')->nullable();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('raw_ip_probes');
        Schema::dropIfExists('raw_ip_inet_probes');

        parent::tearDown();
    }

    /** 落ちたときの文を返す（落ちなければ null） */
    private function failure(string $ip): ?string
    {
        try {
            RawIp::assertNotStored($ip);
        } catch (AssertionFailedError $e) {
            return $e->getMessage();
        }

        return null;
    }

    public function test_it_passes_when_the_ip_is_nowhere(): void
    {
        DB::table('raw_ip_probes')->insert(['label' => 'nothing here', 'note' => 'from 198.51.100.7', 'count' => 3]);

        $this->assertNull($this->failure('203.0.113.7'));
    }

    public function test_it_fails_on_the_ip_in_a_text_column_and_names_only_the_table_and_column(): void
    {
        DB::table('raw_ip_probes')->insert(['label' => 'x', 'note' => 'visitor 203.0.113.7 wrote this']);

        $message = (string) $this->failure('203.0.113.7');

        $this->assertStringContainsString('raw_ip_probes.note', $message);
        $this->assertStringNotContainsString('raw_ip_probes.label', $message);
        $this->assertStringNotContainsString('203.0.113.7', $message);
        $this->assertStringNotContainsString('visitor', $message);
    }

    public function test_it_finds_other_spellings_of_an_ipv6_address(): void
    {
        foreach (['2001:DB8::7', '2001:0db8:0000:0000:0000:0000:0000:0007', '2001:db8:0:0:0:0:0:7'] as $spelling) {
            DB::table('raw_ip_probes')->delete();
            DB::table('raw_ip_probes')->insert(['label' => $spelling]);

            $this->assertStringContainsString('raw_ip_probes.label', (string) $this->failure('2001:db8::7'), $spelling);
        }
    }

    public function test_it_finds_an_ipv4_written_inside_an_ipv6_address(): void
    {
        DB::table('raw_ip_probes')->insert(['label' => '::ffff:203.0.113.7']);

        $this->assertStringContainsString('raw_ip_probes.label', (string) $this->failure('203.0.113.7'));
    }

    public function test_it_does_not_match_a_longer_number(): void
    {
        DB::table('raw_ip_probes')->insert(['label' => '203.0.113.70', 'note' => '1203.0.113.7']);

        $this->assertNull($this->failure('203.0.113.7'));
    }

    public function test_like_wildcards_in_the_data_do_not_match(): void
    {
        // % と _ が LIKE の特別な字として働けば、どの値にも当たる
        DB::table('raw_ip_probes')->insert(['label' => '203_0_113_7', 'note' => '203%113%7']);

        $this->assertNull($this->failure('203.0.113.7'));
    }

    public function test_it_does_not_look_at_the_migrations_table(): void
    {
        Schema::dropIfExists('migrations');
        Schema::create('migrations', function (Blueprint $table) {
            $table->increments('id');
            $table->string('migration');
            $table->integer('batch');
        });

        try {
            DB::table('migrations')->insert(['migration' => '203.0.113.7', 'batch' => 1]);

            $this->assertNull($this->failure('203.0.113.7'));
        } finally {
            Schema::dropIfExists('migrations');
        }
    }

    public function test_it_finds_the_ip_in_a_postgresql_inet_column(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('inet の列は PostgreSQL だけ');
        }

        Schema::create('raw_ip_inet_probes', function (Blueprint $table) {
            $table->id();
            $table->ipAddress('address')->nullable();
        });
        DB::table('raw_ip_inet_probes')->insert(['address' => '2001:db8::7']);

        $this->assertStringContainsString('raw_ip_inet_probes.address', (string) $this->failure('2001:DB8:0:0:0:0:0:7'));
    }

    public function test_a_value_that_is_not_an_ip_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RawIp::assertNotStored('not-an-ip');
    }
}
