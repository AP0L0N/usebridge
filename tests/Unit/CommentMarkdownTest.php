<?php

namespace Tests\Unit;

use App\Support\CommentMarkdown;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CommentMarkdownTest extends TestCase
{
    #[Test]
    public function plain_text_and_safe_markdown_are_kept_as_typed(): void
    {
        foreach ([
            "Checked it.\nLine two\r\n\n- a list\n1. numbered",
            '**bold**, *italic*, `x < y`, [docs](https://example.com/a_(b)) and <https://example.com> <a@b.co>',
            '[mail](mailto:a@b.co) [rel](/tickets/1) [ref][r] [[ticket:tk-1|BR-9]] templates/*.json',
            "[r]: https://example.com\n\na < b, a <= b, <3 and an escaped \\<div>",
            "use `<div>` in the template\n\n```html\n<script>alert(1)</script>\n```\n\n    <b>indented code</b>",
            '[x](<https://example.com/a b>)',
        ] as $body) {
            $this->assertSame($body, CommentMarkdown::sanitize($body));
        }
    }

    #[Test]
    public function raw_html_is_escaped_into_text(): void
    {
        $this->assertSame('use \<div> in the template', CommentMarkdown::sanitize('use <div> in the template'));
        $this->assertSame('\<script>alert(1)\</script>', CommentMarkdown::sanitize('<script>alert(1)</script>'));
        $this->assertSame('\<img src=x onerror=alert(1)>', CommentMarkdown::sanitize('<img src=x onerror=alert(1)>'));
        $this->assertSame('\<a href="javascript:alert(1)">x\</a>', CommentMarkdown::sanitize('<a href="javascript:alert(1)">x</a>'));
        $this->assertSame("\\<div onclick=\"x()\">\nhi\n\\</div>", CommentMarkdown::sanitize("<div onclick=\"x()\">\nhi\n</div>"));
        $this->assertSame('\<!-- c --> \<?php ?> \<!DOCTYPE html>', CommentMarkdown::sanitize('<!-- c --> <?php ?> <!DOCTYPE html>'));
        $this->assertSame('`<b>` then \<b>bold\</b> (unbalanced `', CommentMarkdown::sanitize('`<b>` then <b>bold</b> (unbalanced `'));
        $this->assertSame("a `x\n\n\\<b>` b", CommentMarkdown::sanitize("a `x\n\n<b>` b"));
    }

    #[Test]
    public function unsafe_link_targets_are_dropped(): void
    {
        foreach ([
            '[x](javascript:alert(1))' => '[x]()',
            '[x](JaVaScRiPt:alert(1) "t")' => '[x]( "t")',
            '[x](&#106;avascript:alert(1))' => '[x]()',
            '[x](&#x6A;avascript&colon;alert(1))' => '[x]()',
            '[x](java%73cript:alert(1))' => '[x]()',
            '[x](<jav	ascript:alert(1)>)' => '[x]()',
            '[x](javascript\:alert(1))' => '[x]()',
            '[x](data:text/html;base64,PHNjcmlwdD4=)' => '[x]()',
            '[x](vbscript:msgbox(1))' => '[x]()',
            "[r]: javascript:alert(1)\n\n[y][r]" => "[r]: <>\n\n[y][r]",
            '<javascript:alert(1)>' => '\<javascript:alert(1)>',
            '<data:text/html,x>' => '\<data:text/html,x>',
        ] as $body => $safe) {
            $this->assertSame($safe, CommentMarkdown::sanitize($body), $body);
        }
    }

    #[Test]
    public function the_href_policy_is_http_https_mailto_or_relative(): void
    {
        foreach (['https://a.b', 'http://a.b', 'mailto:a@b.co', 'HTTPS://A.B', '/x', 'x/y', '#top', ''] as $url) {
            $this->assertTrue(CommentMarkdown::isSafeHref($url), $url);
        }
        foreach (['javascript:x', ' javascript:x', 'data:x', 'vbscript:x', 'file:///etc', 'tel:+386', 'java&#09;script:x', "java\0script:x"] as $url) {
            $this->assertFalse(CommentMarkdown::isSafeHref($url), $url);
        }
    }

    #[Test]
    public function control_characters_go_and_a_second_pass_changes_nothing(): void
    {
        $this->assertSame("a\tb\nc", CommentMarkdown::sanitize("a\x00\tb\x07\nc\x1B"));
        $body = "<b>x</b> [y](javascript:z) <javascript:z>\n\n[r]: data:x\n\n```\n<i>\n```";
        $once = CommentMarkdown::sanitize($body);
        $this->assertSame($once, CommentMarkdown::sanitize($once));
    }
}
