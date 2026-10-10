<?php

namespace App\Services;

class ProductHtml
{
    public static function sanitize(?string $html): string
    {
        if (blank($html)) {
            return '';
        }

        $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><blockquote><a><img><table><thead><tbody><tfoot><tr><th><td><span><div><hr>';
        $clean = strip_tags($html, $allowedTags);
        if (! class_exists(\DOMDocument::class)) {
            return preg_replace([
                '/\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu',
                '/\s+(href|src)\s*=\s*(["\'])\s*(?:javascript|data):.*?\2/iu',
            ], '', $clean) ?? '';
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div id="product-body-root">'.$clean.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//*[@*]') ?: [] as $node) {
            foreach (iterator_to_array($node->attributes) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);
                if (str_starts_with($name, 'on') || $name === 'style') {
                    $node->removeAttribute($attribute->name);
                    continue;
                }
                if (in_array($name, ['href', 'src'], true)
                    && preg_match('/^(?:javascript|data):/iu', $value) === 1) {
                    $node->removeAttribute($attribute->name);
                }
            }
        }

        $root = $document->getElementById('product-body-root');
        if (! $root) {
            return '';
        }

        return collect(iterator_to_array($root->childNodes))
            ->map(fn ($node) => $document->saveHTML($node))
            ->implode('');
    }

}
