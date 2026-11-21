<?php

namespace Tests\Unit;

use App\Services\MailExcerpt;
use PHPUnit\Framework\TestCase;

class MailExcerptTest extends TestCase
{
    public function test_markdown_and_html_become_a_single_plain_text_excerpt(): void
    {
        $excerpt = new MailExcerpt;

        $this->assertSame('خبرنامه سلام جهان راهنما', $excerpt->make("# خبرنامه\n\n**سلام جهان** [راهنما](https://example.test)"));
        $this->assertSame('یک دو & سه', $excerpt->make('<style>body { color: red }</style><p>یک<br>دو &amp; سه</p><script>alert(1)</script>'));
        $this->assertSame('رسید سفارش', $excerpt->make('&lt;div&gt;رسید&lt;b&gt; سفارش&lt;/b&gt;&lt;/div&gt;'));
        $this->assertSame('سلام دنیا', $excerpt->make("سلام\r\n\r\n  دنیا"));
        $this->assertStringEndsWith('…', $excerpt->make(str_repeat('نامه ', 90)));
    }
}
