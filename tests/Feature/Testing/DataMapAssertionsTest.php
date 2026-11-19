<?php

namespace Miraqceed\Foundation\Tests\Feature\Testing;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Miraqceed\Foundation\Notices\Notice;
use Miraqceed\Foundation\Retention\DataMap;
use Miraqceed\Foundation\Retention\MapBuilder;
use Miraqceed\Foundation\Testing\DataMapAssertions;
use Miraqceed\Foundation\Tests\Support\Retention\Click;
use Miraqceed\Foundation\Tests\Support\Retention\CreatesRetentionTables;
use Miraqceed\Foundation\Tests\Support\Retention\ShopDataMap;
use Miraqceed\Foundation\Tests\TestCase;
use PHPUnit\Framework\AssertionFailedError;

/**
 * 台帳の書き忘れを見つける試しの助け：表・列・schedule の漏れで落ち、足す1行が出る
 */
class DataMapAssertionsTest extends TestCase
{
    use CreatesRetentionTables;

    private ?string $appPath = null;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('foundation.retention.map', ShopDataMap::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createRetentionTables();
    }

    protected function tearDown(): void
    {
        $this->dropRetentionTables();
        if ($this->appPath !== null) {
            File::deleteDirectory($this->appPath);
        }

        parent::tearDown();
    }

    /** 渡した試しの助けが落ちた文 */
    private function failureOf(\Closure $assertion): string
    {
        try {
            $assertion();
        } catch (AssertionFailedError $e) {
            return $e->getMessage();
        }

        $this->fail('落ちなかった');
    }

    private function scheduleAll(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('model:prune', ['--model' => [Click::class]])->daily();
        $schedule->command('queue:prune-failed --hours=168')->daily();
    }

    public function test_すべての表と列が台帳にあれば通る_migrations_は数えない(): void
    {
        Schema::create('migrations', function (Blueprint $table) {
            $table->id();
            $table->string('migration');
        });

        DataMapAssertions::assertCoversSchema();
    }

    public function test_台帳にない表で落ち_足す1行が出る(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->string('name');
        });

        $message = $this->failureOf(fn () => DataMapAssertions::assertCoversSchema());

        $this->assertStringContainsString("台帳にない表：orders\n  足す1行の例：\$map->table('orders')->erasedWith('user', column: 'user_id');", $message);
        $this->assertStringContainsString("台帳にない表：settings\n  足す1行の例：\$map->table('settings')->kept('残す理由');", $message);
    }

    public function test_行を残す表の_分けていない列で落ちる(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable();
        });

        $message = $this->failureOf(fn () => DataMapAssertions::assertCoversSchema());

        $this->assertStringContainsString('users：行を残す表の、分けていない列：phone', $message);
        $this->assertStringContainsString("erase: ['phone' => null]", $message);
    }

    public function test_台帳にあって_DB_にない表と列で落ちる(): void
    {
        Schema::drop('screenshots');
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
        Schema::table('clicks', function (Blueprint $table) {
            $table->renameColumn('user_id', 'member_id');
        });

        $message = $this->failureOf(fn () => DataMapAssertions::assertCoversSchema());

        $this->assertStringContainsString('台帳の表 screenshots が DB にない', $message);
        $this->assertStringContainsString('contacts：台帳の列 phone が DB にない', $message);
        $this->assertStringContainsString('clicks：erasedWith の列 user_id が DB にない', $message);
    }

    public function test_via_と持ち主の鍵の書き損じで落ちる(): void
    {
        config(['foundation.retention.map' => get_class(new class implements DataMap
        {
            public function define(MapBuilder $map): void
            {
                (new ShopDataMap)->define($map);
                $map->owner('team', table: 'teams');
                $map->table('post_images')->erasedWith('team', via: ['posts', 'post_id', 'team_id']);
            }
        })]);

        $message = $this->failureOf(fn () => DataMapAssertions::assertCoversSchema());

        $this->assertStringContainsString('持ち主 team の表 teams が DB にない', $message);
        $this->assertStringContainsString('post_images：via の列 posts.team_id が DB にない', $message);
    }

    public function test_パッケージの表は_有効でなくても表があれば数え_なければ数えない(): void
    {
        DataMapAssertions::assertCoversSchema();

        // 有効にせずにマイグレーションを写して作った表も、パッケージの台帳の分で足りる
        $this->createEmailChangeTable();
        DataMapAssertions::assertCoversSchema();

        $this->scheduleAll();
        $message = $this->failureOf(fn () => DataMapAssertions::assertScheduled());
        $this->assertStringContainsString('Miraqceed\Foundation\Accounts\EmailChange（foundation_email_changes）', $message);
    }

    public function test_台帳がなければ落ちる(): void
    {
        config(['foundation.retention.map' => null]);

        $this->assertStringContainsString('foundation.retention.map', $this->failureOf(fn () => DataMapAssertions::assertCoversSchema()));
        $this->assertStringContainsString('foundation.retention.map', $this->failureOf(fn () => DataMapAssertions::assertScheduled()));
    }

    public function test_schedule_にないモデルとコマンドで落ち_足す1行が出る(): void
    {
        $message = $this->failureOf(fn () => DataMapAssertions::assertScheduled());

        $this->assertStringContainsString("Schedule::command('model:prune', ['--model' => [\\".Click::class.'::class]])->daily();', $message);
        $this->assertStringContainsString("schedule にないコマンド：queue:prune-failed（failed_jobs）\n  足す1行：Schedule::command('queue:prune-failed')->daily();", $message);
    }

    public function test_schedule_にあれば通る(): void
    {
        $this->scheduleAll();

        DataMapAssertions::assertScheduled();
    }

    public function test_model_prune_の_model_は_文字列で書いても数える(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('model:prune --model="'.Click::class.'"')->daily();
        $schedule->command('queue:prune-failed')->daily();

        DataMapAssertions::assertScheduled();
    }

    public function test_ほかのモデルの_model_prune_では足りない(): void
    {
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('model:prune', ['--model' => [Notice::class]])->daily();
        $schedule->command('queue:prune-failed')->daily();

        $this->assertStringContainsString(Click::class, $this->failureOf(fn () => DataMapAssertions::assertScheduled()));
    }

    public function test_app_Models_のモデルは_model_なしの_model_prune_で足り_except_で除けば落ちる(): void
    {
        $this->appPath = sys_get_temp_dir().'/foundation-retention-app-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->appPath.'/app/Models');
        $class = 'RetentionVisit'.bin2hex(random_bytes(4));
        File::put($this->appPath."/app/Models/{$class}.php", "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Builder;\nuse Illuminate\\Database\\Eloquent\\MassPrunable;\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$class} extends Model\n{\n    use MassPrunable;\n\n    public function prunable(): Builder\n    {\n        return static::query();\n    }\n}\n");
        require_once $this->appPath."/app/Models/{$class}.php";
        $this->app->useAppPath($this->appPath.'/app');

        $model = "App\\Models\\{$class}";
        $map = get_class(new class implements DataMap
        {
            public static string $model;

            public function define(MapBuilder $map): void
            {
                $map->table('visits')->prunedBy(self::$model);
            }
        });
        $map::$model = $model;
        config(['foundation.retention.map' => $map]);

        $schedule = $this->app->make(Schedule::class);
        $event = $schedule->command('model:prune', ['--except' => [Click::class]])->daily();
        DataMapAssertions::assertScheduled();

        $event->command = str_replace(Click::class, $model, (string) $event->command);
        $this->assertStringContainsString($model, $this->failureOf(fn () => DataMapAssertions::assertScheduled()));
    }
}
