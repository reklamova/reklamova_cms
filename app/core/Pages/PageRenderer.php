<?php

declare(strict_types=1);

namespace Reklamova\Cms\Pages;

final class PageRenderer
{
    public function render(array $page): string
    {
        $settings = $this->json((string) ($page['settings_json'] ?? '{}'));

        $classes = [
            'cms-page',
            'cms-page--' . $this->className((string) ($page['template'] ?? 'default')),
            'cms-page--' . $this->className((string) ($settings['layout_width'] ?? 'default')),
        ];

        $html = '<main class="' . implode(' ', $classes) . '">';
        if (empty($settings['hide_title']) && !$this->hasHeroBlock($page)) {
            $html .= '<header class="cms-page__header"><h1>' . $this->e((string) $page['title']) . '</h1>';
            if (!empty($page['excerpt'])) {
                $html .= '<p>' . $this->e((string) $page['excerpt']) . '</p>';
            }
            $html .= '</header>';
        }

        $blocks = $this->blocks($page);
        if ($blocks !== []) {
            foreach ($blocks as $block) {
                $html .= $this->renderBlock($block);
            }
        } else {
            $html .= '<article class="cms-page__content">' . (string) ($page['content'] ?? '') . '</article>';
        }

        $html .= $this->configuredCta($page);
        $html .= $this->configuredForm($page);

        return $html . '</main>';
    }

    /**
     * @return array<string, string>
     */
    public function meta(array $page, string $siteName, string $siteUrl = ''): array
    {
        $title = trim((string) ($page['meta_title'] ?? '')) ?: (string) ($page['title'] ?? $siteName);
        $description = trim((string) ($page['meta_description'] ?? '')) ?: trim((string) ($page['excerpt'] ?? ''));
        $slug = trim((string) ($page['slug'] ?? ''), '/');
        $canonical = trim((string) ($page['canonical_url'] ?? ''));
        if ($canonical === '' && $siteUrl !== '') {
            $canonical = rtrim($siteUrl, '/') . ($slug === 'home' || $slug === '' ? '/' : '/' . $slug);
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'robots' => trim((string) ($page['robots'] ?? 'index,follow')) ?: 'index,follow',
            'image' => trim((string) (($page['og_image'] ?? '') ?: ($page['featured_image'] ?? ''))),
            'schema' => $this->structuredData($page, $siteName, $siteUrl),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function blocks(array $page): array
    {
        $blocks = json_decode((string) ($page['blocks_json'] ?? ''), true);

        return is_array($blocks) ? array_values(array_filter($blocks, 'is_array')) : [];
    }

    private function hasHeroBlock(array $page): bool
    {
        foreach ($this->blocks($page) as $block) {
            if (($block['type'] ?? '') === 'hero') {
                return true;
            }
        }

        return false;
    }

    private function renderBlock(array $block): string
    {
        $type = (string) ($block['type'] ?? 'text');

        return match ($type) {
            'hero' => $this->hero($block),
            'image_text' => $this->imageText($block),
            'cards' => $this->cards($block),
            'faq' => $this->faq($block),
            'cta' => $this->cta($block),
            'gallery' => $this->gallery($block),
            'map' => $this->map($block),
            'form' => $this->form($block),
            'html' => '<section class="cms-block cms-block--html">' . (string) ($block['html'] ?? '') . '</section>',
            default => $this->text($block),
        };
    }

    private function hero(array $block): string
    {
        $media = trim((string) ($block['media_url'] ?? ''));
        $html = '<section class="cms-block cms-hero"><div class="cms-hero__content">'
            . '<span class="cms-eyebrow">Reklamova CMS</span>'
            . '<h1>' . $this->e((string) ($block['title'] ?? '')) . '</h1>'
            . '<p>' . nl2br($this->e((string) ($block['text'] ?? ''))) . '</p>'
            . $this->button($block)
            . '</div>';
        if ($media !== '') {
            $html .= '<figure class="cms-hero__media"><img src="' . $this->e($media) . '" alt=""></figure>';
        }

        return $html . '</section>';
    }

    private function text(array $block): string
    {
        return '<section class="cms-block cms-text">'
            . '<h2>' . $this->e((string) ($block['title'] ?? '')) . '</h2>'
            . '<div>' . nl2br($this->e((string) ($block['text'] ?? ''))) . '</div>'
            . '</section>';
    }

    private function imageText(array $block): string
    {
        $media = trim((string) ($block['media_url'] ?? ''));

        return '<section class="cms-block cms-image-text">'
            . ($media !== '' ? '<figure><img src="' . $this->e($media) . '" alt=""></figure>' : '')
            . '<div><h2>' . $this->e((string) ($block['title'] ?? '')) . '</h2><p>' . nl2br($this->e((string) ($block['text'] ?? ''))) . '</p>' . $this->button($block) . '</div>'
            . '</section>';
    }

    private function cards(array $block): string
    {
        $html = '<section class="cms-block cms-cards"><header><h2>' . $this->e((string) ($block['title'] ?? '')) . '</h2><p>' . nl2br($this->e((string) ($block['text'] ?? ''))) . '</p></header><div>';
        foreach (($block['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = trim((string) ($item['url'] ?? ''));
            $html .= '<article><h3>' . $this->e((string) ($item['title'] ?? '')) . '</h3><p>' . $this->e((string) ($item['text'] ?? '')) . '</p>'
                . ($url !== '' ? '<a href="' . $this->e($url) . '">Zobacz wiecej</a>' : '')
                . '</article>';
        }

        return $html . '</div></section>';
    }

    private function faq(array $block): string
    {
        $html = '<section class="cms-block cms-faq"><h2>' . $this->e((string) ($block['title'] ?? 'Pytania i odpowiedzi')) . '</h2>';
        foreach (($block['items'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $html .= '<details><summary>' . $this->e((string) ($item['question'] ?? '')) . '</summary><p>' . $this->e((string) ($item['answer'] ?? '')) . '</p></details>';
        }

        return $html . '</section>';
    }

    private function cta(array $block): string
    {
        $variant = $this->className((string) ($block['cta_variant'] ?? 'standard'));

        return '<section class="cms-block cms-cta cms-cta--' . $variant . '"><h2>' . $this->e((string) ($block['title'] ?? '')) . '</h2><p>'
            . nl2br($this->e((string) ($block['text'] ?? ''))) . '</p>' . $this->button($block) . '</section>';
    }

    private function gallery(array $block): string
    {
        $items = $block['gallery'] ?? [];
        if (!is_array($items) || $items === []) {
            return '';
        }

        $html = '<section class="cms-block cms-gallery"><header><h2>' . $this->e((string) ($block['title'] ?? 'Galeria')) . '</h2><p>' . nl2br($this->e((string) ($block['text'] ?? ''))) . '</p></header><div>';
        foreach ($items as $item) {
            if (!is_array($item) || trim((string) ($item['url'] ?? '')) === '') {
                continue;
            }
            $html .= '<figure><img src="' . $this->e((string) $item['url']) . '" alt="' . $this->e((string) ($item['alt'] ?? '')) . '"></figure>';
        }

        return $html . '</div></section>';
    }

    private function map(array $block): string
    {
        $address = trim((string) ($block['map_address'] ?? ''));
        $embed = trim((string) ($block['map_embed_url'] ?? ''));
        $html = '<section class="cms-block cms-map"><div><h2>' . $this->e((string) ($block['title'] ?? 'Mapa')) . '</h2><p>' . nl2br($this->e((string) (($block['text'] ?? '') ?: $address))) . '</p></div>';
        if ($embed !== '') {
            $html .= '<iframe src="' . $this->e($embed) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
        } elseif ($address !== '') {
            $url = 'https://www.google.com/maps?q=' . rawurlencode($address) . '&output=embed';
            $html .= '<iframe src="' . $this->e($url) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>';
        }

        return $html . '</section>';
    }

    private function form(array $block): string
    {
        return $this->formMarkup([
            'enabled' => true,
            'type' => (string) ($block['form_type'] ?? 'contact'),
            'title' => (string) (($block['title'] ?? '') ?: 'Formularz kontaktowy'),
            'text' => (string) ($block['text'] ?? ''),
            'marketing_consent' => false,
        ]);
    }

    private function configuredCta(array $page): string
    {
        $config = $this->json((string) ($page['cta_config_json'] ?? '{}'));
        if (empty($config['enabled'])) {
            return '';
        }

        return $this->cta([
            'title' => (string) ($config['title'] ?? ''),
            'text' => (string) ($config['text'] ?? ''),
            'button_label' => (string) ($config['button_label'] ?? ''),
            'button_url' => (string) ($config['button_url'] ?? ''),
            'cta_variant' => (string) ($config['variant'] ?? 'standard'),
        ]);
    }

    private function configuredForm(array $page): string
    {
        $config = $this->json((string) ($page['form_config_json'] ?? '{}'));
        if (empty($config['enabled'])) {
            return '';
        }

        return $this->formMarkup($config);
    }

    private function formMarkup(array $config): string
    {
        $title = trim((string) ($config['title'] ?? 'Formularz kontaktowy')) ?: 'Formularz kontaktowy';
        $type = $this->className((string) ($config['type'] ?? 'contact'));
        $text = trim((string) ($config['text'] ?? ''));
        $marketing = !empty($config['marketing_consent']);

        return '<section class="cms-block cms-form cms-form--' . $type . '"><header><h2>' . $this->e($title) . '</h2>'
            . ($text !== '' ? '<p>' . nl2br($this->e($text)) . '</p>' : '')
            . '</header><form method="post" action="/api/forms/submit"><input type="hidden" name="form_type" value="' . $this->e($type) . '">'
            . '<label>Imie i nazwisko<input name="name" autocomplete="name"></label>'
            . '<label>E-mail<input type="email" name="email" autocomplete="email" required></label>'
            . '<label>Wiadomosc<textarea name="message" required></textarea></label>'
            . ($marketing ? '<label class="cms-form__check"><input type="checkbox" name="marketing_consent" value="1"> Zgadzam sie na kontakt marketingowy.</label>' : '')
            . '<button class="cms-button">Wyslij</button></form></section>';
    }

    private function button(array $block): string
    {
        $label = trim((string) ($block['button_label'] ?? ''));
        $url = trim((string) ($block['button_url'] ?? ''));
        if ($label === '' || $url === '') {
            return '';
        }

        return '<a class="cms-button" href="' . $this->e($url) . '">' . $this->e($label) . '</a>';
    }

    private function structuredData(array $page, string $siteName, string $siteUrl): string
    {
        $settings = $this->json((string) ($page['settings_json'] ?? '{}'));
        $schemaConfig = $this->json((string) ($page['schema_json'] ?? '{}'));
        $items = [];

        if (!empty($settings['schema_breadcrumb_enabled'])) {
            $slug = trim((string) ($page['slug'] ?? ''), '/');
            $url = rtrim($siteUrl, '/') . ($slug === '' || $slug === 'home' ? '/' : '/' . $slug);
            $items[] = [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => $siteName, 'item' => rtrim($siteUrl, '/') . '/'],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => (string) ($page['title'] ?? ''), 'item' => $url],
                ],
            ];
        }

        if (!empty($settings['schema_faq_enabled'])) {
            foreach ($this->blocks($page) as $block) {
                if (($block['type'] ?? '') !== 'faq') {
                    continue;
                }
                $questions = [];
                foreach (($block['items'] ?? []) as $item) {
                    if (!is_array($item) || trim((string) ($item['question'] ?? '')) === '') {
                        continue;
                    }
                    $questions[] = [
                        '@type' => 'Question',
                        'name' => (string) ($item['question'] ?? ''),
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string) ($item['answer'] ?? '')],
                    ];
                }
                if ($questions !== []) {
                    $items[] = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $questions];
                }
            }
        }

        if (!empty($settings['schema_local_business_enabled'])) {
            $business = is_array($schemaConfig['local_business'] ?? null) ? $schemaConfig['local_business'] : [];
            $items[] = array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'LocalBusiness',
                'name' => (string) (($business['name'] ?? '') ?: $siteName),
                'address' => (string) ($business['address'] ?? ''),
                'telephone' => (string) ($business['phone'] ?? ''),
                'email' => (string) ($business['email'] ?? ''),
                'url' => rtrim($siteUrl, '/') . '/',
            ]);
        }

        $custom = trim((string) ($schemaConfig['custom_jsonld'] ?? ''));
        if ($custom !== '') {
            json_decode($custom, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $items[] = json_decode($custom, true);
            }
        }

        if ($items === []) {
            return '';
        }

        $html = '';
        foreach ($items as $item) {
            $html .= '<script type="application/ld+json">' . json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
        }

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $value): array
    {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function className(string $value): string
    {
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value) ?: 'default';

        return trim($value, '-') ?: 'default';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
