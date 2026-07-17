<?php

return new class {
    public function up(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'cms_modules')) {
            return;
        }

        $labels = [
            'pages' => ['Strony', 'Treść', 20, 1, 1],
            'media' => ['Media', 'Treść', 30, 1, 1],
            'business' => ['Strona główna', 'Treść', 40, 1, 1],
            'knowledge' => ['Aktualności / Poradniki', 'Treść', 50, 1, 1],
            'leads' => ['Formularze', 'Treść', 60, 1, 1],
            'forms' => ['Formularze', 'Treść', 65, 1, 1],
            'catalog' => ['Produkty', 'Sprzedaż', 100, 1, 1],
            'landing' => ['Landing pages', 'Marketing', 160, 1, 1],
            'trust' => ['Opinie i referencje', 'Marketing', 170, 1, 1],
            'privacy' => ['Prywatność i cookies', 'Marketing', 180, 1, 1],
            'updates' => ['Aktualizacje CMS', 'Reklamova / techniczne', 900, 0, 1],
            'seo' => ['SEO', 'Reklamova / techniczne', 910, 0, 1],
        ];

        $statement = $pdo->prepare(
            'UPDATE cms_modules
             SET menu_label = ?,
                 menu_group = ?,
                 sort_order = ?,
                 visible_in_client_nav = ?,
                 visible_in_admin_nav = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE slug = ?'
        );

        foreach ($labels as $slug => [$label, $group, $order, $clientVisible, $adminVisible]) {
            $statement->execute([$label, $group, $order, $clientVisible, $adminVisible, $slug]);
        }
    }

    public function down(PDO $pdo): void
    {
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $statement = $pdo->prepare('SHOW TABLES LIKE ?');
            $statement->execute([$table]);

            return (bool) $statement->fetchColumn();
        } catch (Throwable) {
            return false;
        }
    }
};
