<?php

namespace App\LandingPages;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Filament\Forms\Components\RichEditor\RichContentRenderer;

/**
 * The only way user-written formatting reaches a public page. The output is
 * rebuilt from scratch out of a short allow-list (bold, italic, lists,
 * paragraphs, links), so nothing else, and no attribute, can survive.
 */
class RichText
{
    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'form', 'textarea', 'select', 'button', 'head', 'title'];

    /** An array is the editor's own unsaved document; it is turned to HTML first and cleaned like any other. */
    public static function clean(string|array|null $html): string
    {
        if (is_array($html)) {
            $html = RichContentRenderer::make($html)->toHtml();
        }

        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>'.$html.'</body>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $doc->getElementsByTagName('body')->item(0);

        return $body ? trim(self::walk($body)) : '';
    }

    /** Plain text, for meta descriptions. */
    public static function text(string|array|null $html): string
    {
        return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(self::clean($html)), ENT_QUOTES, 'UTF-8')));
    }

    public static function safeHref(?string $href): ?string
    {
        $href = trim((string) $href);

        return preg_match('#^(https?://[^\s<>"\']+|mailto:[^\s<>"\']+|tel:[+0-9 ()-]+)$#i', $href) ? $href : null;
    }

    private static function walk(DOMNode $node): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $out .= htmlspecialchars($child->nodeValue ?? '', ENT_QUOTES, 'UTF-8');

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::DROP, true)) {
                continue;
            }

            $inner = self::walk($child);

            $out .= match ($tag) {
                'strong', 'b' => '<strong>'.$inner.'</strong>',
                'em', 'i' => '<em>'.$inner.'</em>',
                'p', 'ul', 'ol', 'li' => '<'.$tag.'>'.$inner.'</'.$tag.'>',
                'br' => '<br>',
                'a' => ($href = self::safeHref($child->getAttribute('href')))
                    ? '<a href="'.htmlspecialchars($href, ENT_QUOTES, 'UTF-8').'" rel="noopener nofollow" target="_blank">'.$inner.'</a>'
                    : $inner,
                default => $inner,
            };
        }

        return $out;
    }
}
