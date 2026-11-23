<?php

namespace Miraqceed\Foundation\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Miraqceed\Foundation\Commands\InstallCommand;
use Miraqceed\Foundation\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        // 一時フォルダーは乱数の名前（並列でも衝突しない）
        $this->dir = sys_get_temp_dir().'/foundation-install-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->app->setBasePath($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*.md') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function sections(string $text): int
    {
        return substr_count($text, '<!-- foundation:start -->');
    }

    public function test_install_writes_section_once(): void
    {
        file_put_contents($this->dir.'/CLAUDE.md', "# 自分の指針\n\n手書きの行\n");

        $this->assertSame(0, Artisan::call('foundation:install'));
        $this->assertSame(0, Artisan::call('foundation:install'));

        $text = file_get_contents($this->dir.'/CLAUDE.md');
        $this->assertSame(1, $this->sections($text));
        $this->assertSame(1, substr_count($text, '<!-- foundation:end -->'));
        $this->assertStringContainsString('手書きの行', $text);
        $this->assertStringContainsString('laravel-foundation', $text);
        $this->assertFileDoesNotExist($this->dir.'/AGENTS.md');
    }

    public function test_install_replaces_existing_section(): void
    {
        file_put_contents($this->dir.'/AGENTS.md', "前\n<!-- foundation:start -->\n古い内容\n<!-- foundation:end -->\n後\n");
        file_put_contents($this->dir.'/CLAUDE.md', "# c\n");

        Artisan::call('foundation:install');

        $agents = file_get_contents($this->dir.'/AGENTS.md');
        $this->assertStringNotContainsString('古い内容', $agents);
        $this->assertSame(1, $this->sections($agents));
        $this->assertStringStartsWith("前\n", $agents);
        $this->assertStringEndsWith("後\n", $agents);
        // あるものすべてに書く
        $this->assertSame(1, $this->sections(file_get_contents($this->dir.'/CLAUDE.md')));
    }

    public function test_install_creates_agents_md_when_none(): void
    {
        $this->assertSame(0, Artisan::call('foundation:install'));

        $this->assertFileExists($this->dir.'/AGENTS.md');
        $this->assertSame(1, $this->sections(file_get_contents($this->dir.'/AGENTS.md')));
        $this->assertFileDoesNotExist($this->dir.'/CLAUDE.md');
    }

    /**
     * 指針（Boost が読む）と foundation:install が書く節は同じ原本から作る。
     * どちらにも欠かせない語が入っていることを確かめる（欠けると、そのとおりに組んだアプリで守りが外れる）。
     */
    public function test_guideline_and_installed_section_share_required_words(): void
    {
        $guideline = (string) file_get_contents(InstallCommand::GUIDELINE);
        $section = InstallCommand::section();

        $required = [
            // テストの守りは入れた時点で効く。外への通信の遮断は任意（新しいアプリでは真）
            'test-safety.md', 'ignore_connections', '入れた時点で効く', 'prevent_stray_requests', 'Unknown database',
            // CSV
            'CsvResponse::download', 'Rows::keyset', 'CsvReader::parse',
            // 生の IP を残さない：セッション・回数の制限の鍵（既定は無効）・残す値のハッシュの鍵は APP_KEY と別・試しの助け
            'privacy.md', 'foundation.privacy.sessions_without_ip', 'foundation.privacy.keyed_rate_limiter', 'IpHash::of', 'FOUNDATION_IP_HASH_KEY', 'RawIp::assertNotStored',
            // 実在しないメールアドレス：判定・欄の規則・送る前の差し込み（既定は無効）。形の確かめではない
            'reserved-email-domains.md', 'ReservedEmailDomains::isReserved', 'NotReservedEmailDomain', 'drop_reserved_recipients', 'メールアドレスの形の確かめではない',
            // 本番の設定の点検：自前で書き直さず、調べを足す。値を出さない
            'deploy-check.md', 'foundation:deploy-check', 'deploy_check.checks', 'Result::later()', '値なしで書く',
            // メールの中の安全：訪問者の文は全体を渡して打ち消す。リンクは APP_URL から（既定は無効）。偽の Host は入口で断る
            'mail-safety.md', "MailText::escape(\$user->name.' さん')", 'links_from_app_url', 'trustHosts()', 'X-Forwarded-Host',
            // ログイン（3つとも要る。authenticateUsing が抜けると回数の制限が消える）
            'limiters', 'authenticateThrough', 'authenticateUsing', 'trustProxies',
            // 逆プロキシ：'*' は 13.20.0 から訪問者の送った X-Forwarded-For を信じる
            "'*'` にしない", '13.20.0',
            // CSP（web のグループでなくグローバルの先頭。web のグループでは 404・503・413 に付かない）
            'foundation.security.enabled', 'foundation.security-headers', 'Nonce::get', 'Vite::cspNonce()', 'report_route', 'frame-ancestors',
            '$middleware->prepend(\\Miraqceed\\Foundation\\Security\\SecurityHeaders::class)', 'withoutMiddleware()',
            // 稼働の確かめ（health: を使わず Health::route() にする）
            'health.md', "Health::route('/up')", 'LogicException', 'foundation.health.checks', 'DiagnosingHealth',
            // 見張り
            'foundation.monitoring.enabled', 'migrate', 'watched', 'foundation:record', 'monitoring.alerts.log.channel', 'stderr',
            'environments()', 'foundation.watch:', 'throttle_minutes - 5',
            // 知らせの表は model:prune で消す（アプリが schedule に入れる）
            'model:prune', '--model', 'prune_days',
            // database のキャッシュの期限切れの行は foundation:cache-prune で消す（アプリが schedule に入れる）
            'foundation:cache-prune',
            // AI の費用の上限
            'foundation.ai.enabled', 'Laravel AI SDK', 'AiLimitReached', 'when_uncountable', 'Miraqceed\\Foundation\\Ai\\AiUsage',
            // 利用者の URL をサーバーから開く（守りは別のパッケージ。Guzzle は直った版から。PHP 8.3 では作らずに知らせる）
            'ssrf.md', 'cboxdk/laravel-ssrf', 'Http::ssrf()', 'PublicUrl', '7.15.2', 'HTTP_PROXY', 'assertSafeRedirect',
            'PHP 8.3 のアプリでは',
            // DB の接続の時間帯（PostgreSQL は名前。MySQL の時差は夏時間の無い時間帯だけ）
            'database-timezone.md', 'foundation:db-timezone', '9 時間西', '夏時間',
            // DB のロック（自前の GET_LOCK・SET を書かない。トランザクションの外で取る。1205 の後に続けない。SQLite は守らない）
            'database-locks.md', 'NamedLock::class', 'LockNotAcquired', 'LockWait::class', 'Transactions::assertOutside', 'LockErrors::isLockWaitTimeout',
            'HeldLocks::row', '名前つきのロック → 行', '同じトランザクションを続けない', 'forceIndex()', 'SQLite では `NamedLock`',
            // 子プロセスの環境変数
            'child-processes.md', 'ChildEnvironment::only', 'ExecutableFinder',
            // 自分で書くときの注意
            '自分で書くときの注意', '\\z', '\\p{sc=Han}', 'PCRE2 10.40', 'SESSION_ENCRYPT', 'createApplication()',
            'auth.session', 'remember_token',
            // アカウントの乗っ取りの守り（聞き手はアプリが登録する。logoutOtherDevices を使わない）
            'accounts.md', 'PasswordSessions', 'afterChange', 'afterReset', 'EndSessionsAfterChange', 'EndSessionsAfterReset',
            'logoutOtherDevices()', 'authenticateSessions()', 'password_sessions',
            // メールアドレスの変更（表は有効にしたときだけ。確かめは本人のログインの中。管理者の操作も通す）
            'foundation.email_changes.enabled', 'EmailChanges', 'NotHeldEmail', 'AccountLockdown', 'cancelPending',
            'session()->invalidate()', '管理者の操作も含めて', 'Miraqceed\\Foundation\\Accounts\\EmailChange`',
            // 確認のリンク・そろえ方・引き取り（既定を使い auth を外さない。自前なら本人と鍵つきの指紋。外部の連携は確認済みにだけ）
            'EmailVerificationRequest', '鍵つきの指紋', 'getPreviousKeys()', 'url.intended', 'そろえて保存し、そろえて探す', '確認済みのアドレスにだけ',
            '$wasUnverified',
            // メールアドレスの入力の整え方（ミドルウェアはアプリが入れる。保存と比べの2つの形。Gmail だけ）
            'email-input.md', 'NormalizeEmailInput', 'EmailInput::normalize()', 'EmailInput::canonical()', 'foundation.mail.input_fields',
            '_confirmation', 'CanonicalEmailKey', 'ほかのドメインに広げない',
            // 自動のメールの見出しと送信のドメイン（id に乱数を入れない。DNS を引けなければ skip で外す）
            'AutomatedMessage::mark(', 'Auto-Submitted', 'Message-ID', 'foundation.mail.auto_submitted', 'mail_domain', 'dkim_selector',
            // 使い捨てのメール（一覧はアプリが置く。ログインの欄には付けない）
            'NotDisposableEmail', 'foundation.mail.disposable_domains',
            // 試みの数え：メールを送る口はすべての頼みを数える。再設定の申請と設定を分ける
            'メールを送る口', '`throttle:` で数えない', '名前を分ける',
            // Turnstile（閉じる側に倒す。外す切り替えを作らない。IP は既定で送らない）
            'turnstile.md', 'TurnstilePassed', 'foundation.turnstile', 'VerifyTurnstileOnNamedRoutes', 'foundation.turnstile.send_ip',
            'challenges.cloudflare.com',
            // 1回だけ使える合言葉（表は有効にしたときだけ。受け取りは Attempts の共有の枠で包む。平文を flash・ログに入れない）
            'one-time-codes.md', 'OneTimeCodes', 'foundation.one_time_codes.enabled', 'FailuresShared', 'no-store',
            'Miraqceed\\Foundation\\OneTimeCodes\\OneTimeCode', '$claimed->result', 'DB::afterCommit()',
            // 機械用のトークン（表は有効にしたときだけ。その場で切り替えない・今の鍵でだけ入れ替える・人の API キーは Sanctum）
            'machine-tokens.md', 'Miraqceed\\Foundation\\MachineTokens\\MachineTokens', 'foundation.machine_tokens.enabled', 'MachineKind',
            'その場で切り替えない', '今の鍵でだけ通る', 'MachineTokens::issue()',
            // 失敗の記録と要求の id（投げない記録・ファイルの間引き・文は伏せない・id はサーバーで作る）
            'failures.md', 'Miraqceed\\Foundation\\Failures\\FailureReporter', 'redactIp', 'Duplicate entry',
            'zend.exception_ignore_args', 'AssignRequestId', 'RequestId::headers()', 'ReportFailure::simulate()',
            // 共用サーバーで cron からキューを回す（関数で登録する・timeout と retry_after・sync と cache:clear）
            'queue.md', 'Miraqceed\Foundation\Queue\CronWorker::schedule', 'timeout + 10 <= retry_after', 'pcntl', 'QUEUE_CONNECTION=sync',
            'cache:clear', 'QueueCheck', 'queue_worker', 'queue_timeouts',
            // ファイル名とファイルの中身（名前を収める・保存のキーにしない・SVG を通さない・位置の情報を消す）
            'files.md', 'uploads.md', 'Miraqceed\Foundation\Files\FileName::name', 'FileName::forUpload()', 'image:allow_svg',
            'FileSignature::fromStream()', 'ImageMetadata::strip', 'nosniff',
            // 要求の本文の上限と中間処理の並び順（回数の制限より前・JSON は解く前に断れない・認証の印・試しで固定）
            'http.md', 'foundation.body-limit', 'Miraqceed\Foundation\Http\LimitBodySize', 'client_max_body_size', 'AuthenticatesRequests',
            'prependToPriorityList', 'Miraqceed\Foundation\Testing\RouteMiddleware::assertBefore',
            // 日本語の入力の誤りの文（写す・上書きしない・name を attributes に置かない・試しで確かめる）
            'validation-ja.md', 'foundation-lang-ja', 'Translations::missing', 'Translations::assertNoEnglish', 'Form Request の `attributes()`',
            // 知らせを1回だけ送る（回を鍵に・戻して繰り返し確かめる・トランザクションの中・キューの holds・期限で消す）
            'once-notices.md', 'Miraqceed\Foundation\Notices\OnceNotices::send', 'foundation.notices.enabled', 'keepOnFailure',
            'NoticeResult::Deferred', '$claim->holds()', 'retry_after', 'Miraqceed\Foundation\Notices\Notice',
            // 消す決まりの台帳（すべての表・行を残す表のすべての列・試しの2行・握って確かめ直す・本番の schedule）
            'retention.md', 'Miraqceed\Foundation\Retention\DataMap', 'foundation.retention.map', 'DataMapAssertions::assertCoversSchema()',
            'DataMapAssertions::assertScheduled()', 'Miraqceed\Foundation\Retention\Erasure', 'OwnerErased', 'retention_schedule',
            // 住所まわり（外の API に問い合わせない・一覧はアプリが作る・桁の数で区切らない・出典を残す）
            'japan-address.md', 'Miraqceed\Foundation\Japan\PostalAddressBook', 'foundation:postal-codes', 'foundation.japan.postal_codes.path',
            'postal_codes', 'PhoneNumber::format()', 'Rules\JapanesePhoneNumber', 'Rules\JapanesePostalCode', '加工して作成',
            // 外のデータのファイル（自前で取って書かない・使う口で確かめる・同じディレクトリの一時のファイル・allowed_classes・配る側の決まり）
            'data-files.md', 'Miraqceed\Foundation\DataFiles\DataSource', 'foundation.data_files.sources', 'foundation:data-files',
            '===END PRIVATE DOMAINS===', 'sys_get_temp_dir()', 'withoutOverlapping()', 'allowedClasses', 'data_files', 'ふつうのファイルシステム',
            // 改ざんの検知（自前で書かない・schedule の助け・作り直す前に調べる・storage に PHP を置かない・読み込む場所を外さない）
            'integrity.md', 'Integrity::schedule', 'foundation:integrity-snapshot', 'foundation:integrity-check', 'evenInMaintenanceMode()',
            'serializable_classes', 'integrity-check',
        ];
        foreach ($required as $word) {
            $this->assertStringContainsString($word, $guideline, "指針に {$word} がない");
            $this->assertStringContainsString($word, $section, "install の節に {$word} がない");
        }
    }

    /** 見出しの前には空の行を置く（無いと、前の箇条書きの続きと読まれる表示がある） */
    public function test_every_guideline_heading_follows_a_blank_line(): void
    {
        $lines = explode("\n", (string) file_get_contents(InstallCommand::GUIDELINE));

        foreach ($lines as $i => $line) {
            if ($i > 0 && str_starts_with($line, '### ')) {
                $this->assertSame('', $lines[$i - 1], "{$line} の前に空の行がない");
            }
        }
    }

    public function test_installed_section_is_plain_markdown(): void
    {
        $section = InstallCommand::section();

        // Blade・Boost の書き方は残さない
        foreach (['@verbatim', '@endverbatim', '<code-snippet', '</code-snippet>', '@{{'] as $blade) {
            $this->assertStringNotContainsString($blade, $section);
        }
        // 日本語の文字を割らない（\R を /u なしで使うと \x85 で切れる）
        $this->assertTrue(mb_check_encoding($section, 'UTF-8'));
        $this->assertStringContainsString("```php\n", $section);
        $this->assertSame(substr_count($section, '```') % 2, 0);
    }

    public function test_install_works_with_parts_disabled(): void
    {
        config([
            'foundation.security.enabled' => false,
            'foundation.monitoring.enabled' => false,
        ]);

        $this->assertSame(0, Artisan::call('foundation:install'));
        $this->assertFileExists($this->dir.'/AGENTS.md');
    }
}
