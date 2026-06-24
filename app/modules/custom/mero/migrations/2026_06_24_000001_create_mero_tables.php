<?php

return new class {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mero_leads (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(32) NOT NULL UNIQUE,
                type VARCHAR(60) NOT NULL DEFAULT "contact",
                source VARCHAR(120) NULL,
                status VARCHAR(60) NOT NULL DEFAULT "new",
                name VARCHAR(190) NOT NULL,
                phone VARCHAR(60) NOT NULL,
                email VARCHAR(190) NOT NULL,
                location VARCHAR(190) NULL,
                payload JSON NULL,
                result JSON NULL,
                notes TEXT NULL,
                ip_address VARCHAR(64) NULL,
                user_agent VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mero_articles (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(190) NOT NULL,
                slug VARCHAR(190) NOT NULL UNIQUE,
                excerpt TEXT NULL,
                content MEDIUMTEXT NULL,
                status VARCHAR(40) NOT NULL DEFAULT "draft",
                category VARCHAR(120) NULL,
                cover_image VARCHAR(255) NULL,
                meta_title VARCHAR(190) NULL,
                meta_description TEXT NULL,
                published_at DATE NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec('INSERT INTO cms_modules (slug, version, enabled, source, installed_at) VALUES ("mero", "0.1.0", 1, "custom", CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE enabled = 1, version = VALUES(version), source = "custom"');

        $settings = [
            'notice' => 'Wartosci techniczne do uzupelnienia przez administratora. Nie sa finalnym cennikiem MERO.',
            'currency' => 'PLN',
            'tax_label' => 'brutto/netto - zgodnie z ustawieniami administratora',
            'range_percent' => 15,
            'base_prices' => [
                'sso' => 2100,
                'ssz' => 2900,
                'developer' => 4700,
                'turnkey' => 6500,
            ],
            'roof_multipliers' => [
                'dwuspadowy' => 1,
                'wielospadowy' => 1.12,
                'plaski' => 1.06,
                'inny' => 1.08,
            ],
            'garage_multipliers' => [
                'brak' => 1,
                'jednostanowiskowy' => 1.06,
                'dwustanowiskowy' => 1.1,
                'w_bryle' => 1.08,
                'osobny' => 1.12,
            ],
            'basement_surcharges' => [
                'brak' => 0,
                'czesciowe' => 65000,
                'pelne' => 120000,
            ],
            'extras' => [
                'pompa_ciepla' => 45000,
                'rekuperacja' => 30000,
                'fotowoltaika' => 28000,
                'ogrzewanie_podlogowe' => 22000,
                'taras' => 25000,
                'ogrodzenie' => 35000,
                'kostka_brukowa' => 30000,
            ],
            'admin_email' => 'biuro@mero.pl',
        ];

        $statement = $pdo->prepare('INSERT INTO cms_settings (setting_key, setting_value) VALUES ("mero.calculator", ?) ON DUPLICATE KEY UPDATE setting_value = setting_value');
        $statement->execute([json_encode($settings, JSON_UNESCAPED_SLASHES)]);

        $pages = [
            ['MERO - budowa domow i materialy budowlane', 'home', '<p>MERO laczy wykonawstwo domow jednorodzinnych z zapleczem materialowym. Ta tresc startowa jest edytowalna w panelu CMS.</p><p>Klient moze zarzadzac podstronami w module Strony, a kalkulator i leady pozostaja w module MERO.</p>'],
            ['Budowa domow', 'budowa-domow', '<p>Podstrona uslugi budowy domow. Opisz zakresy, proces wspolpracy i przewagi MERO.</p>'],
            ['Kalkulator budowy domu', 'kalkulator-budowy-domu', '<p>Kalkulator korzysta z ustawien modulu MERO. Formularz zapisuje lead w bazie instalacji.</p><div data-mero-calculator></div>'],
            ['Etapy budowy', 'etapy-budowy', '<p>Opisz etapy od analizy projektu po odbior prac.</p>'],
            ['Pakiety budowy', 'pakiety-budowy', '<p>Stan surowy otwarty, stan surowy zamkniety, stan deweloperski i dom pod klucz.</p>'],
            ['Realizacje', 'realizacje', '<p>Miejsce na realizacje i zakresy wykonanych prac.</p>'],
            ['Hurtownia materialow budowlanych', 'hurtownia-materialow-budowlanych', '<p>Zaplecze materialowe MERO, dostawy i doradztwo techniczne.</p>'],
            ['Poradnik', 'poradnik', '<p>Lista poradnikowych wpisow jest zarzadzana w module MERO.</p>'],
            ['Kontakt', 'kontakt', '<p>Skontaktuj sie z MERO w sprawie budowy domu, materialow lub wyceny.</p><div data-mero-contact></div>'],
            ['Polityka prywatnosci', 'polityka-prywatnosci', '<p>Opis przetwarzania danych z formularzy, kalkulatora, cookies i kontaktu.</p>'],
        ];

        $statement = $pdo->prepare('INSERT IGNORE INTO cms_pages (title, slug, content, status) VALUES (?, ?, ?, "published")');
        foreach ($pages as $page) {
            $statement->execute($page);
        }

        $articles = [
            ['Ile kosztuje budowa domu w Malopolsce i na Slasku?', 'ile-kosztuje-budowa-domu-malopolska-slask', 'Koszt budowy zalezy od metrazu, projektu, technologii i zakresu prac.', 'Koszt budowy domu warto traktowac jako zakres zalezny od projektu, lokalizacji i standardu. Kalkulator MERO jest punktem startu do rozmowy, a nie finalna oferta.'],
            ['Stan deweloperski - co obejmuje i kiedy sie oplaca?', 'stan-deweloperski-co-obejmuje', 'Stan deweloperski przybliza inwestora do wykonczenia domu, ale wymaga jasnego zakresu.', 'Ten zakres oplaca sie inwestorom, ktorzy chca ograniczyc liczbe osobnych ekip i lepiej skoordynowac materialy oraz harmonogram.'],
        ];

        $statement = $pdo->prepare('INSERT IGNORE INTO mero_articles (title, slug, excerpt, content, status, category, published_at) VALUES (?, ?, ?, ?, "published", "Poradnik inwestora", CURRENT_DATE)');
        foreach ($articles as $article) {
            $statement->execute($article);
        }
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS mero_articles');
        $pdo->exec('DROP TABLE IF EXISTS mero_leads');
        $pdo->exec('DELETE FROM cms_settings WHERE setting_key = "mero.calculator"');
        $pdo->exec('DELETE FROM cms_modules WHERE slug = "mero"');
    }
};
