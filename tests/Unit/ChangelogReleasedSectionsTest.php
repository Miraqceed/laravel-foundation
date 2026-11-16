<?php

namespace Miraqceed\Foundation\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * CHANGELOG の出した版の節（「## 0.x.0」）は、その版のタグの時点の同じ節と同じでなければならない。
 *
 * 版を出す前の main から分かれた PR を、版を出した後に入れると、git は項を「## 未公開」の下でなく、
 * 出した版の節の中に、衝突もなく入れる（項の前後の行が、版の見出しの前後と同じため）。その間違いをここで捕まえる。
 * 版を出す PR では、まだタグが無いので、その版の節は比べない。
 *
 * タグが要る。タグの無い手元（浅い clone・git の外）では飛ばす。CI（GITHUB_ACTIONS）でタグが読めなければ落とす
 * （checkout に fetch-depth: 0 が要る。飛ばすと、この守りが黙って外れる）
 */
class ChangelogReleasedSectionsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @return list<string> 今のコミットから辿れる、出した版のタグ（v1.2.3 の形だけ） */
    private static function tags(): array
    {
        $process = new Process(['git', '-C', self::root(), 'tag', '--list', 'v*', '--merged', 'HEAD']);
        $process->run();
        if (! $process->isSuccessful()) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\R/u', trim($process->getOutput())) ?: [],
            fn (string $tag) => preg_match('/\Av\d+\.\d+\.\d+\z/', $tag) === 1,
        ));
    }

    /**
     * 「## 」で始まる見出しごとの節（見出しの後から次の「## 」の前まで。後ろの空の行は除く）
     *
     * @return array<string, string>
     */
    public static function sections(string $changelog): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split('/\R/u', $changelog) ?: [] as $line) {
            if (str_starts_with($line, '## ')) {
                $current = trim(substr($line, 3));
                $sections[$current] = '';

                continue;
            }
            if ($current !== null) {
                $sections[$current] .= $line."\n";
            }
        }

        return array_map(fn (string $body) => rtrim($body), $sections);
    }

    public function test_sections_are_split_by_second_level_headings(): void
    {
        $sections = self::sections("# 変更の記録\n\n## 未公開\n\n- 新しい項\n\n## 0.2.0\n\n- 前の項\n- もう1つ\n\n## 0.1.0\n\n- 最初\n");

        $this->assertSame(['未公開', '0.2.0', '0.1.0'], array_keys($sections));
        $this->assertSame("\n- 前の項\n- もう1つ", $sections['0.2.0']);
        $this->assertSame("\n- 最初", $sections['0.1.0']);
    }

    public function test_released_sections_match_the_changelog_at_their_tags(): void
    {
        $tags = self::tags();
        if ($tags === []) {
            if (getenv('GITHUB_ACTIONS') === 'true') {
                $this->fail('CI で git のタグが読めない。checkout に fetch-depth: 0 を付ける');
            }
            $this->markTestSkipped('git のタグが無い（浅い clone か git の外）');
        }

        $current = self::sections((string) file_get_contents(self::root().'/CHANGELOG.md'));
        $compared = 0;
        foreach ($tags as $tag) {
            $version = substr($tag, 1);
            $show = new Process(['git', '-C', self::root(), 'show', "{$tag}:CHANGELOG.md"]);
            $show->run();
            $atTag = $show->isSuccessful() ? self::sections($show->getOutput()) : [];
            // そのタグの時点で、版の節が無ければ比べない（CHANGELOG を書き始める前の版）
            if (! array_key_exists($version, $atTag)) {
                continue;
            }

            $this->assertArrayHasKey($version, $current, "## {$version} の節が消えている");
            $this->assertSame(
                $atTag[$version],
                $current[$version],
                "## {$version} の節が、タグ {$tag} の時点と違う。版を出した後に入った項は「## 未公開」の下へ移す",
            );
            $compared++;
        }

        $this->assertGreaterThan(0, $compared, '比べた版が無い');
    }
}
