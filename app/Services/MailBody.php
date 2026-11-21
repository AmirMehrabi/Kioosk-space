<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Str;

class MailBody
{
    private const TAGS = ['p', 'div', 'span', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'strong', 'em', 'b', 'i', 'u', 's', 'del', 'sub', 'sup', 'blockquote', 'pre', 'code',
        'ul', 'ol', 'li', 'a', 'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'bdi', 'bdo'];

    private const DROP = ['head', 'script', 'style', 'svg', 'math', 'iframe', 'object', 'embed', 'template', 'noscript', 'form', 'video', 'audio'];

    public function render(string $text, string $html): string
    {
        if (trim($html) !== '') {
            $result = $this->sanitize($html);
            if ($result !== '') {
                return $result;
            }
        }

        return $this->sanitize(Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 25,
            'renderer' => ['soft_break' => '<br>'],
        ]));
    }

    public function plainText(string $html): string
    {
        $withBreaks = preg_replace('~<(?:br\s*/?|/(?:p|div|li|blockquote|pre|h[1-6]|tr))>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\n{3,}/', "\n\n", $text));
    }

    private function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $source = new DOMDocument;
        $source->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $body = $source->getElementsByTagName('body')->item(0);
        if (! $body) {
            return '';
        }

        $clean = new DOMDocument('1.0', 'UTF-8');
        $root = $clean->createDocumentFragment();
        $this->children($body, $root, $clean);

        return trim($clean->saveHTML($root) ?: '');
    }

    private function children(DOMNode $source, DOMNode $destination, DOMDocument $document): void
    {
        foreach ($source->childNodes as $child) {
            if ($child instanceof DOMText) {
                $destination->appendChild($document->createTextNode($child->textContent));

                continue;
            }

            if (! $child instanceof DOMElement || $child->hasAttribute('hidden') || in_array($child->tagName, self::DROP, true)) {
                continue;
            }

            if ($child->tagName === 'img') {
                $destination->appendChild($document->createTextNode($child->getAttribute('alt')));

                continue;
            }

            if (! in_array($child->tagName, self::TAGS, true)) {
                $this->children($child, $destination, $document);

                continue;
            }

            $node = $document->createElement($child->tagName);
            $direction = strtolower($child->getAttribute('dir'));
            if (in_array($direction, ['rtl', 'ltr', 'auto'], true)) {
                $node->setAttribute('dir', $direction);
            }
            if ($child->tagName === 'a') {
                $href = trim($child->getAttribute('href'));
                if (preg_match('~\A(?:https?://|mailto:)[^\p{C}\s]+\z~iu', $href)) {
                    $node->setAttribute('href', $href);
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer');
                }
            }

            $this->children($child, $node, $document);
            $destination->appendChild($node);
        }
    }
}
