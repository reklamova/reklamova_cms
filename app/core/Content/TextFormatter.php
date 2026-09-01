<?php

declare(strict_types=1);

namespace Reklamova\Cms\Content;

final class TextFormatter
{
    public static function withLinks(string $text): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $pattern = '/\[([^\]\r\n]+)\]\(([^)\s]+)\)(\{new-tab\})?/u';
        preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE);
        if (empty($matches[0])) {
            return nl2br($escape($text));
        }

        $html = '';
        $offset = 0;
        foreach ($matches[0] as $index => $match) {
            $full = (string) $match[0];
            $position = (int) $match[1];
            $html .= $escape(substr($text, $offset, $position - $offset));
            $label = (string) ($matches[1][$index][0] ?? '');
            $url = (string) ($matches[2][$index][0] ?? '');
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            $isLocal = str_starts_with($url, '/') && !str_starts_with($url, '//');
            $isWeb = in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
            if ($isLocal || $isWeb) {
                $newTab = (string) ($matches[3][$index][0] ?? '') === '{new-tab}';
                $target = $newTab ? ' target="_blank" rel="noopener noreferrer"' : '';
                $html .= '<a href="' . $escape($url) . '"' . $target . '>' . $escape($label) . '</a>';
            } else {
                $html .= $escape($full);
            }
            $offset = $position + strlen($full);
        }

        return nl2br($html . $escape(substr($text, $offset)));
    }
}
