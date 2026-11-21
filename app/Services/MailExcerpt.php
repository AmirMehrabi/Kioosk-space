<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Str;

class MailExcerpt
{
    public function make(string $preview): string
    {
        $source = html_entity_decode($preview, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $source = preg_replace('~<(script|style|template|noscript|svg)\b[^>]*>.*?(?:</\1\s*>|$)~is', '', $source);
        $html = Str::markdown($source, ['allow_unsafe_links' => false, 'max_nesting_level' => 25]);
        $html = preg_replace('~<\s*(?:br\b[^>]*|/(?:p|div|h[1-6]|li|blockquote|pre|td|th|tr|section|article)\s*)>~i', ' $0', $html);

        $document = new DOMDocument;
        $document->loadHTML('<meta charset="utf-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//script | //style | //template | //noscript | //svg | //*[@hidden]') as $element) {
            $element->parentNode->removeChild($element);
        }

        foreach ($xpath->query('//img[@alt]') as $image) {
            $image->parentNode->replaceChild($document->createTextNode($image->getAttribute('alt')), $image);
        }

        $text = $document->getElementsByTagName('body')->item(0)?->textContent ?? '';

        return Str::limit(Str::squish($text), 160, '…');
    }
}
