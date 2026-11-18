<?php

namespace Miraqceed\Foundation\Tests\Feature\DeployCheck;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\DeployCheck\Check;
use Miraqceed\Foundation\DeployCheck\Checks\AppDebug;
use Miraqceed\Foundation\DeployCheck\Checks\AppEnv;
use Miraqceed\Foundation\DeployCheck\Checks\AppKey;
use Miraqceed\Foundation\DeployCheck\Checks\AppUrl;
use Miraqceed\Foundation\DeployCheck\Checks\AttemptsBypass;
use Miraqceed\Foundation\DeployCheck\Checks\Boost;
use Miraqceed\Foundation\DeployCheck\Checks\Drivers;
use Miraqceed\Foundation\DeployCheck\Checks\Mail;
use Miraqceed\Foundation\DeployCheck\Checks\Migrations;
use Miraqceed\Foundation\DeployCheck\Checks\SessionSecureCookie;
use Miraqceed\Foundation\DeployCheck\Checks\ViteHot;
use Miraqceed\Foundation\DeployCheck\Checks\ViteManifest;
use Miraqceed\Foundation\DeployCheck\Checks\Writable;
use Miraqceed\Foundation\DeployCheck\Status;
use Miraqceed\Foundation\Tests\TestCase;

/**
 * 既定の調べ：通る設定と通らない設定。値（鍵・パスワード）は出さない
 */
class DefaultChecksTest extends TestCase
{
    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    private function tmp(): string
    {
        return $this->tmp ??= sys_get_temp_dir().'/foundation-deploy-check-'.bin2hex(random_bytes(6));
    }

    private function statusOf(Check $check): Status
    {
        return $check->run()->status;
    }

    public function test_app_env_と_app_debug_と_session_secure_cookie(): void
    {
        config(['app.env' => 'production', 'app.debug' => false, 'session.secure' => true]);
        $this->assertSame(Status::Ok, $this->statusOf(new AppEnv));
        $this->assertSame(Status::Ok, $this->statusOf(new AppDebug));
        $this->assertSame(Status::Ok, $this->statusOf(new SessionSecureCookie));

        config(['app.env' => 'staging', 'app.debug' => true, 'session.secure' => null]);
        $this->assertSame(Status::Fail, $this->statusOf(new AppEnv));
        $this->assertSame(Status::Fail, $this->statusOf(new AppDebug));
        $this->assertSame(Status::Fail, $this->statusOf(new SessionSecureCookie));
    }

    public function test_app_url_は_https_の_URL(): void
    {
        config(['app.url' => 'https://app.example']);
        $this->assertSame(Status::Ok, $this->statusOf(new AppUrl));

        foreach (['http://app.example', 'app.example', '', null] as $url) {
            config(['app.url' => $url]);
            $this->assertSame(Status::Fail, $this->statusOf(new AppUrl), (string) $url);
        }
    }

    public function test_app_key_は暗号の方式に合う長さで_値を出さない(): void
    {
        $good = 'base64:'.base64_encode(str_repeat('k', 32));
        $short = 'base64:'.base64_encode(str_repeat('s', 16));

        config(['app.cipher' => 'AES-256-CBC', 'app.key' => $good, 'app.previous_keys' => [$good]]);
        $this->assertSame(Status::Ok, $this->statusOf(new AppKey));

        config(['app.key' => $short, 'app.previous_keys' => []]);
        $result = (new AppKey)->run();
        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString('AES-256-CBC', (string) $result->detail);
        $this->assertStringNotContainsString($short, (string) $result->detail);

        // 16バイトの鍵は AES-128-CBC には合う
        config(['app.cipher' => 'AES-128-CBC']);
        $this->assertSame(Status::Ok, $this->statusOf(new AppKey));

        config(['app.cipher' => 'AES-256-CBC', 'app.key' => $good, 'app.previous_keys' => [$good, $short]]);
        $result = (new AppKey)->run();
        $this->assertSame(Status::Fail, $result->status);
        $this->assertStringContainsString('2 番目', (string) $result->detail);

        config(['app.key' => '', 'app.previous_keys' => []]);
        $this->assertSame(Status::Fail, $this->statusOf(new AppKey));
    }

    public function test_boost_は入っていなければ_skip_入っていれば_false_を求める(): void
    {
        $this->assertSame(Status::Skip, $this->statusOf(new Boost('Laravel\Boost\NotInstalled')));

        // 入っている（クラスがある）とみなす
        $installed = new Boost(self::class);
        config(['boost.enabled' => true]);
        $this->assertSame(Status::Fail, $this->statusOf($installed));
        config(['boost.enabled' => null]);
        $this->assertSame(Status::Fail, $this->statusOf($installed));
        config(['boost.enabled' => false]);
        $this->assertSame(Status::Ok, $this->statusOf($installed));
    }

    public function test_drivers_は_array_で落ち_種類の名前だけを出す(): void
    {
        config([
            'cache.default' => 'database', 'cache.stores.database.driver' => 'database',
            'session.driver' => 'database', 'queue.default' => 'database', 'queue.connections.database.driver' => 'database',
        ]);
        $result = (new Drivers)->run();
        $this->assertSame(Status::Ok, $result->status);
        $this->assertSame('キャッシュ database・セッション database・キュー database', $result->detail);

        config(['cache.default' => 'array', 'cache.stores.array.driver' => 'array']);
        $this->assertSame(Status::Fail, $this->statusOf(new Drivers));

        config(['cache.default' => 'database', 'session.driver' => 'array']);
        $this->assertSame(Status::Fail, $this->statusOf(new Drivers));

        // 名前でない値は出さない
        config(['session.driver' => 'redis://user:secret@host']);
        $result = (new Drivers)->run();
        $this->assertStringNotContainsString('secret', (string) $result->detail);
        $this->assertStringContainsString('セッション （不明）', (string) $result->detail);
    }

    public function test_mail_は既定値のままでは落ちる(): void
    {
        $real = [
            'mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.mail.test',
            'mail.mailers.smtp.password' => 'pw-secret-xyz', 'mail.from.address' => 'noreply@mail.test',
        ];
        config($real);
        // .test は予約のドメインなので落ちる
        $this->assertSame(Status::Fail, $this->statusOf(new Mail));

        config(['mail.from.address' => 'noreply@mail.example-sender.zz']);
        $this->assertSame(Status::Ok, $this->statusOf(new Mail));

        $cases = [
            ['mail.default' => 'log', 'mail.mailers.log.transport' => 'log'],
            ['mail.default' => 'array', 'mail.mailers.array.transport' => 'array'],
            ['mail.mailers.smtp.host' => '127.0.0.1'],
            ['mail.mailers.smtp.host' => null],
            ['mail.from.address' => 'hello@example.com'],
            ['mail.from.address' => 'not-an-address'],
        ];
        foreach ($cases as $case) {
            config(['mail.from.address' => 'noreply@mail.example-sender.zz'] + $real);
            config($case);
            $result = (new Mail)->run();
            $this->assertSame(Status::Fail, $result->status, json_encode($case));
            $this->assertStringNotContainsString('pw-secret-xyz', (string) $result->detail);
        }
    }

    public function test_vite_hot_と_vite_manifest(): void
    {
        $base = $this->tmp();
        File::ensureDirectoryExists($base.'/public/build');
        $this->app->setBasePath($base);
        $this->app->usePublicPath($base.'/public');

        $this->assertSame(Status::Ok, $this->statusOf(new ViteHot));
        file_put_contents($base.'/public/hot', 'http://localhost:5173');
        $this->assertSame(Status::Fail, $this->statusOf(new ViteHot));

        // Vite を使わないアプリ
        $this->assertSame(Status::Skip, $this->statusOf(new ViteManifest));

        file_put_contents($base.'/vite.config.js', 'export default {}');
        $this->assertSame(Status::Fail, $this->statusOf(new ViteManifest));

        file_put_contents($base.'/public/build/manifest.json', '{broken');
        $this->assertSame(Status::Fail, $this->statusOf(new ViteManifest));

        file_put_contents($base.'/public/build/manifest.json', json_encode(['resources/js/app.js' => ['file' => 'assets/app.js']]));
        $this->assertSame(Status::Ok, $this->statusOf(new ViteManifest));
    }

    public function test_writable_は書けないフォルダーの名前を出す(): void
    {
        $base = $this->tmp();
        foreach (['storage/logs', 'storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'bootstrap/cache'] as $dir) {
            File::ensureDirectoryExists("{$base}/{$dir}");
        }
        $this->app->useStoragePath($base.'/storage');
        $this->app->useBootstrapPath($base.'/bootstrap');
        $this->assertSame(Status::Ok, $this->statusOf(new Writable));

        File::deleteDirectory($base.'/storage/framework/views');
        $result = (new Writable)->run();
        $this->assertSame(Status::Fail, $result->status);
        $this->assertSame('書けない: storage/framework/views', $result->detail);
    }

    public function test_migrations_は表がない_残っている_流し済みを見分ける(): void
    {
        $base = $this->tmp();
        File::ensureDirectoryExists($base.'/migrations');
        file_put_contents($base.'/migrations/2026_01_01_000000_create_things_table.php', "<?php\nreturn new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void {} };\n");
        $this->app->useDatabasePath($base);
        // CI の MySQL・PostgreSQL では、ほかの試しの migrations の表が残っている。この試しだけの表で見る（migrator を作る前に決める）
        config(['database.migrations.table' => 'foundation_deploy_check_migrations']);
        Schema::dropIfExists('foundation_deploy_check_migrations');

        try {
            $result = (new Migrations)->run();
            $this->assertSame(Status::Fail, $result->status);
            $this->assertStringContainsString('表がない', (string) $result->detail);

            $repository = app('migrator')->getRepository();
            $repository->createRepository();
            $result = (new Migrations)->run();
            $this->assertSame(Status::Fail, $result->status);
            $this->assertStringContainsString('残っている: 1 件', (string) $result->detail);

            $repository->log('2026_01_01_000000_create_things_table', 1);
            $this->assertSame(Status::Ok, $this->statusOf(new Migrations));
        } finally {
            Schema::dropIfExists('foundation_deploy_check_migrations');
        }
    }

    public function test_attempts_bypass(): void
    {
        $this->assertSame(Status::Ok, $this->statusOf(new AttemptsBypass));
        config(['foundation.attempts.bypass' => true]);
        $this->assertSame(Status::Fail, $this->statusOf(new AttemptsBypass));
    }

    public function test_コマンドのどの出力にも鍵とパスワードの値が出ない(): void
    {
        $key = 'base64:'.base64_encode(str_repeat('z', 16));
        config([
            'app.key' => $key, 'app.previous_keys' => [$key],
            'mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.password' => 'pw-secret-xyz', 'mail.from.address' => 'hello@example.com',
            'app.url' => 'http://user:url-secret@app.example',
        ]);

        foreach ([[], ['--json' => true]] as $options) {
            Artisan::call('foundation:deploy-check', $options);
            $output = Artisan::output();
            foreach ([$key, substr($key, 7), 'pw-secret-xyz', 'url-secret', 'hello@example.com'] as $secret) {
                $this->assertStringNotContainsString($secret, $output);
            }
        }
    }
}
