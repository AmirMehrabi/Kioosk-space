<?php

namespace Tests\Unit;

use App\Services\MailBody;
use PHPUnit\Framework\TestCase;

class MailBodyTest extends TestCase
{
    public function test_html_keeps_structure_and_safe_links_without_loading_remote_content(): void
    {
        $html = (new MailBody)->render('Plain alternative', '<html dir="rtl"><head><style>p{color:red}</style></head><body><h1>سلام</h1><p onclick="bad()">رسید <a href="https://example.test">سفارش</a><img src="https://example.test/pixel"><a href="javascript:alert(1)">پیوند</a></p><script>bad()</script></body></html>');

        $this->assertStringContainsString('<h1>سلام</h1>', $html);
        $this->assertStringContainsString('href="https://example.test"', $html);
        foreach (['Plain alternative', 'onclick', 'javascript:', 'pixel', '<script', '<style'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
    }

    public function test_plain_text_and_markdown_render_without_interpreting_raw_tags(): void
    {
        $html = (new MailBody)->render("# سلام\n\n**رسید**\nسفارش <img src=x>", '');

        $this->assertStringContainsString('<h1>سلام</h1>', $html);
        $this->assertStringContainsString('<strong>رسید</strong>', $html);
        $this->assertStringContainsString('<br>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }
}
