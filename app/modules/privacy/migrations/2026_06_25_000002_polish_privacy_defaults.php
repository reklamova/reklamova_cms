<?php

return new class {
    public function up(PDO $pdo): void
    {
        $this->updateDefaultSettings($pdo);
        $this->updateDefaultCategories($pdo);
    }

    private function updateDefaultSettings(PDO $pdo): void
    {
        $updates = [
            'banner_title' => [
                'new' => 'Prywatność i cookies',
                'old' => ['Prywatnosc i cookies'],
            ],
            'banner_text' => [
                'new' => 'Używamy niezbędnych cookies do działania strony. Dodatkowe technologie uruchamiamy dopiero po Twojej zgodzie.',
                'old' => [
                    'Uzywamy niezbednych cookies do dzialania strony. Dodatkowe technologie uruchamiamy dopiero po Twojej zgodzie.',
                ],
            ],
            'button_accept_all' => [
                'new' => 'Akceptuję wszystko',
                'old' => ['Akceptuje wszystko'],
            ],
            'button_save' => [
                'new' => 'Zapisz wybór',
                'old' => ['Zapisz wybor'],
            ],
            'footer_privacy_label' => [
                'new' => 'Ustawienia prywatności',
                'old' => ['Ustawienia prywatnosci'],
            ],
        ];

        foreach ($updates as $key => $data) {
            $oldValues = array_map(
                static fn (string $value): string => json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $data['old']
            );
            $placeholders = implode(',', array_fill(0, count($oldValues), '?'));
            $statement = $pdo->prepare('UPDATE privacy_settings SET value = ?, updated_at = CURRENT_TIMESTAMP WHERE `key` = ? AND value IN (' . $placeholders . ')');
            $statement->execute(array_merge([
                json_encode($data['new'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $key,
            ], $oldValues));
        }
    }

    private function updateDefaultCategories(PDO $pdo): void
    {
        $categories = [
            'necessary' => ['Niezbędne', 'Sesja, bezpieczeństwo, CSRF i zapamiętanie decyzji.', 'Niezbędne technologie są wymagane do prawidłowego działania strony i panelu. Nie można ich wyłączyć.'],
            'analytics' => ['Analityczne', 'Pomiar ruchu, statystyki i diagnostyka.', 'Pomagają mierzyć sposób korzystania ze strony, np. GA4, Clarity lub Hotjar. Domyślnie zablokowane do czasu zgody.'],
            'marketing' => ['Marketingowe', 'Pomiar reklam i remarketing.', 'Używane przez Google Ads, Meta Pixel, TikTok Pixel lub LinkedIn Insight Tag. Domyślnie zablokowane do czasu zgody.'],
            'functional' => ['Funkcjonalne', 'Mapy, wideo, czat i dodatkowe funkcje.', 'Pozwalają uruchamiać osadzone mapy, wideo, czaty lub inne funkcje, które nie są niezbędne technicznie.'],
            'personalization' => ['Personalizacyjne', 'Preferencje i personalizacja doświadczenia.', 'Pozwalają zapamiętywać preferencje i personalizować treści poza zakresem technicznie niezbędnym.'],
        ];

        $statement = $pdo->prepare('UPDATE privacy_categories SET name = ?, short_description = ?, full_description = ?, updated_at = CURRENT_TIMESTAMP WHERE slug = ?');
        foreach ($categories as $slug => $category) {
            $statement->execute([$category[0], $category[1], $category[2], $slug]);
        }
    }

    public function down(PDO $pdo): void
    {
    }
};
