<?php

namespace App\Support\Content;

use Dom\Element;
use Dom\HTMLDocument;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Makes the HTML of a record's body safe to store and print unescaped, and reads or edits its image nodes.
 *
 * Images are stored as <img data-media-id="…" alt="…">; their address is filled in by BodyRenderer.
 */
class BodySanitizer
{
    /** The longest body accepted, in bytes. The sanitizer's own default (20 000) would cut a long body silently. */
    private const MAX_LENGTH = 4_000_000;

    /**
     * Sanitizes editor HTML with an allowlist; null when nothing is left.
     */
    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = $this->sanitizer()->sanitize($this->withoutHeadElements($html));

        $document = self::load($clean);

        foreach (self::images($document) as $image) {
            if (! $image->hasAttribute('data-media-id')) {
                $image->remove();
            }
        }

        return self::html($document);
    }

    /**
     * Ids of the image nodes, in order, each once.
     *
     * @return list<int>
     */
    public function mediaIds(?string $html): array
    {
        if ($html === null || ! str_contains($html, '<img')) {
            return [];
        }

        $ids = [];

        foreach (self::images(self::load($html)) as $image) {
            $id = (int) $image->getAttribute('data-media-id');

            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * The body without the images of one file; null when nothing is left.
     */
    public function withoutMedia(?string $html, int $mediaId): ?string
    {
        if ($html === null || ! str_contains($html, '<img')) {
            return $html;
        }

        $document = self::load($html);

        foreach (self::images($document) as $image) {
            if ((int) $image->getAttribute('data-media-id') === $mediaId) {
                $image->remove();
            }
        }

        return self::html($document);
    }

    /**
     * Parses a body fragment as UTF-8 HTML.
     */
    public static function load(string $html): HTMLDocument
    {
        return HTMLDocument::createFromString('<body>'.$html.'</body>', LIBXML_NOERROR, 'UTF-8');
    }

    /**
     * Serializes the fragment parsed by load(); null when it is empty.
     */
    public static function html(HTMLDocument $document): ?string
    {
        $html = '';

        foreach ($document->body->childNodes as $node) {
            $html .= $document->saveHtml($node);
        }

        return trim($html) === '' ? null : $html;
    }

    /**
     * The image nodes, as a list safe to change while looping.
     *
     * @return list<Element>
     */
    public static function images(HTMLDocument $document): array
    {
        return iterator_to_array($document->body->querySelectorAll('img'), false);
    }

    /**
     * The sanitizer treats <style> and <title> (head elements) as unknown inside a body and, with unknown elements
     * blocked to keep their text, would leave their contents as text. They are removed with their contents first.
     */
    private function withoutHeadElements(string $html): string
    {
        if (preg_match('/<\s*(style|title|head|link|meta|base)\b/i', $html) !== 1) {
            return $html;
        }

        $document = self::load($html);

        foreach (iterator_to_array($document->body->querySelectorAll('style, title, head, link, meta, base'), false) as $element) {
            $element->remove();
        }

        return (string) self::html($document);
    }

    private function sanitizer(): HtmlSanitizer
    {
        $config = (new HtmlSanitizerConfig)
            ->defaultAction(HtmlSanitizerAction::Block)
            ->withMaxInputLength(self::MAX_LENGTH)
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->withAttributeSanitizer(new MediaIdAttributeSanitizer);

        foreach (['p', 'h2', 'h3', 'h4', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'blockquote', 'br', 'hr', 'code', 'pre'] as $element) {
            $config = $config->allowElement($element);
        }

        $config = $config
            ->allowElement('a', ['href', 'target', 'rel'])
            ->allowElement('img', ['data-media-id', 'alt']);

        foreach (['script', 'iframe', 'object', 'embed', 'svg', 'math', 'form', 'template', 'noscript', 'textarea', 'select'] as $element) {
            $config = $config->dropElement($element);
        }

        return new HtmlSanitizer($config);
    }
}
