<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\View;

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\TestCase;
use Plugins\View\Exceptions\ViewException;
use Plugins\View\Infrastructure\PhpViewRenderer;

/**
 * Regression cover for VW-01 (no escaping primitive) and VW-02 (unconfined
 * absolute paths — LFI, and RCE since the file is require()d).
 */
#[CoversFunction('esc')]
#[CoversFunction('esc_url')]
final class ViewSecurityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/hkm-view-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/views', 0775, true);
        file_put_contents($this->root . '/views/ok.php', 'SAFE:<?= $esc($x ?? "") ?>');

        // A file OUTSIDE the view roots — stands in for /etc/passwd or an
        // uploaded .php payload.
        file_put_contents($this->root . '/outside.php', 'PWNED');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/views/*') ?: [] as $f) { @unlink($f); }
        @unlink($this->root . '/outside.php');
        @rmdir($this->root . '/views');
        @rmdir($this->root);
    }

    private function renderer(): PhpViewRenderer
    {
        return new PhpViewRenderer([$this->root . '/views']);
    }

    // ── VW-02 ───────────────────────────────────────────────────────────────

    public function test_a_view_inside_the_root_renders(): void
    {
        // Data goes through setData(); render()'s second argument is options.
        $out = $this->renderer()->setData(['x' => 'hi'])->render('ok');

        self::assertSame('SAFE:hi', $out);
    }

    public function test_an_absolute_path_outside_the_view_roots_is_refused(): void
    {
        // Previously require()d verbatim: local file inclusion, and RCE for any
        // .php the attacker could place.
        $this->expectException(ViewException::class);
        $this->renderer()->render($this->root . '/outside.php');
    }

    public function test_traversal_out_of_the_view_root_is_refused(): void
    {
        $this->expectException(ViewException::class);
        $this->renderer()->render('../outside');
    }

    // ── VW-01 ───────────────────────────────────────────────────────────────

    public function test_esc_neutralises_html(): void
    {
        self::assertSame('&lt;script&gt;alert(1)&lt;/script&gt;', esc('<script>alert(1)</script>'));
        self::assertSame('&quot;&gt;&lt;img&gt;', esc('"><img>'));
    }

    public function test_esc_substitutes_malformed_utf8_rather_than_returning_empty(): void
    {
        // Without ENT_SUBSTITUTE this returns '' and the value silently vanishes.
        self::assertNotSame('', esc("bad\xB1\x31bytes"));
    }

    public function test_esc_url_rejects_javascript_scheme(): void
    {
        // htmlspecialchars alone does NOT help here: there are no HTML
        // metacharacters in javascript:alert(1), so it would pass through and
        // still execute on click.
        self::assertSame('', esc_url('javascript:alert(1)'));
        self::assertSame('', esc_url('JavaScript:alert(1)'));
        self::assertSame('', esc_url('data:text/html,<script>alert(1)</script>'));
    }

    public function test_esc_url_allows_normal_links(): void
    {
        self::assertSame('https://example.test/a?b=1', esc_url('https://example.test/a?b=1'));
        self::assertSame('/relative/path', esc_url('/relative/path'));
    }

    public function test_esc_json_cannot_break_out_of_a_script_block(): void
    {
        self::assertStringNotContainsString('</script>', esc_json(['x' => '</script><script>alert(1)']));
    }

    public function test_template_data_cannot_clobber_the_escaper(): void
    {
        // EXTR_SKIP: an "esc" key in the data would otherwise replace the
        // escaper and neutralise every escape on the page.
        $out = $this->renderer()->setData(['x' => '<b>', 'esc' => 'pwned'])->render('ok');

        self::assertSame('SAFE:&lt;b&gt;', $out, 'the escaper must survive a hostile data key');
    }
}
