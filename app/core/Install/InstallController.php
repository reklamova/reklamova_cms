<?php

declare(strict_types=1);

namespace Reklamova\Cms\Install;

use PDO;
use Reklamova\Cms\Auth\Csrf;
use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Database\Migrator;
use Reklamova\Cms\Health\HealthCheck;
use Reklamova\Cms\Support\Url;

final class InstallController
{
    public function __construct(private array $container)
    {
    }

    public function handle(): void
    {
        $installer = new Installer($this->container);
        if ($installer->isInstalled()) {
            Url::redirect('/admin');
        }

        $error = null;
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!Csrf::verify($_POST['_csrf'] ?? null)) {
                $error = 'Sesja formularza wygasla. Sprobuj ponownie.';
            } else {
                try {
                    $this->install($_POST);
                    Url::redirect('/admin');
                } catch (\Throwable $exception) {
                    $error = $exception->getMessage();
                }
            }
        }

        $health = (new HealthCheck($this->container))->run();
        $this->render($health, $error);
    }

    private function install(array $input): void
    {
        $required = ['site_name', 'site_url', 'db_host', 'db_name', 'db_user', 'admin_email', 'admin_password'];
        foreach ($required as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                throw new \RuntimeException('Pole jest wymagane: ' . $field);
            }
        }

        $this->writeConfigFiles($input);
        $this->container['active_modules'] = $this->activeModulesFromInput($input);
        $pdo = (new ConnectionFactory($this->container))->make();
        (new Migrator($this->container))->runCoreMigrations();
        (new Migrator($this->container))->runActiveModuleMigrations();
        $this->createAdmin($pdo, $input);
        $this->createHomePage($pdo, $input);

        file_put_contents($this->container['storage_path'] . '/installed.lock', date(DATE_ATOM));
    }

    private function writeConfigFiles(array $input): void
    {
        $this->writePhpConfig('app.php', [
            'name' => trim((string) $input['site_name']),
            'client_name' => trim((string) ($input['client_name'] ?: $input['site_name'])),
            'client_logo' => trim((string) ($input['client_logo'] ?? '')),
            'url' => rtrim(trim((string) $input['site_url']), '/'),
            'timezone' => 'Europe/Warsaw',
            'debug' => false,
            'active_theme' => 'client-default',
            'active_modules' => $this->activeModulesFromInput($input),
        ]);

        $this->writePhpConfig('database.php', [
            'driver' => 'mysql',
            'host' => trim((string) $input['db_host']),
            'port' => (int) ($input['db_port'] ?: 3306),
            'database' => trim((string) $input['db_name']),
            'username' => trim((string) $input['db_user']),
            'password' => (string) ($input['db_password'] ?? ''),
            'charset' => 'utf8mb4',
        ]);

        $this->writePhpConfig('license.php', [
            'site_id' => trim((string) ($input['site_id'] ?? '')),
            'site_key' => trim((string) ($input['site_key'] ?? '')),
            'license_server' => 'https://updates.reklamova.pl',
        ]);
    }

    private function activeModulesFromInput(array $input): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) ($input['active_modules'] ?? '')))));
    }

    private function writePhpConfig(string $file, array $data): void
    {
        $export = var_export($data, true);
        file_put_contents($this->container['config_path'] . '/' . $file, "<?php\n\nreturn " . $export . ";\n");
    }

    private function createAdmin(PDO $pdo, array $input): void
    {
        $statement = $pdo->prepare('INSERT INTO cms_users (email, name, password_hash, role, active) VALUES (?, ?, ?, "client_admin", 1)');
        $statement->execute([
            trim((string) $input['admin_email']),
            trim((string) ($input['admin_name'] ?: 'Administrator')),
            password_hash((string) $input['admin_password'], PASSWORD_DEFAULT),
        ]);
    }

    private function createHomePage(PDO $pdo, array $input): void
    {
        $statement = $pdo->prepare('INSERT IGNORE INTO cms_pages (title, slug, content, status) VALUES (?, "home", ?, "published")');
        $statement->execute([
            trim((string) $input['site_name']),
            '<p>Strona została uruchomiona w Reklamova CMS. Treść można edytować w panelu administracyjnym.</p>',
        ]);
    }

    private function render(array $health, ?string $error): void
    {
        header('Content-Type: text/html; charset=utf-8');

        $errorHtml = $error ? '<div class="error">' . htmlspecialchars($error, ENT_QUOTES) . '</div>' : '';
        $healthHtml = htmlspecialchars(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES);

        echo '<!doctype html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>Instalacja Reklamova CMS</title><link rel="stylesheet" href="/assets/core/admin.css"></head><body>'
            . '<main class="login"><section class="panel"><h1>Instalacja Reklamova CMS</h1>' . $errorHtml
            . '<form method="post">' . Csrf::field()
            . '<label>Nazwa strony<input name="site_name" value=""></label>'
            . '<label>Nazwa klienta w panelu<input name="client_name" value="" placeholder="np. Nazwa firmy klienta"></label>'
            . '<label>Logo klienta w panelu<input name="client_logo" value="" placeholder="/assets/images/logo.svg"></label>'
            . '<label>Adres strony<input name="site_url" value="https://"></label>'
            . '<label>Host bazy<input name="db_host" value="localhost"></label>'
            . '<label>Port bazy<input name="db_port" value="3306"></label>'
            . '<label>Nazwa bazy<input name="db_name"></label>'
            . '<label>Użytkownik bazy<input name="db_user"></label>'
            . '<label>Hasło bazy<input type="password" name="db_password"></label>'
            . '<label>Site ID<input name="site_id"></label>'
            . '<label>Site key<input name="site_key"></label>'
            . '<label>Aktywne moduly<input name="active_modules" placeholder="np. mero"></label>'
            . '<label>Imie administratora<input name="admin_name" value="Administrator"></label>'
            . '<label>Email administratora<input type="email" name="admin_email"></label>'
            . '<label>Hasło administratora<input type="password" name="admin_password"></label>'
            . '<button>Uruchom CMS</button></form></section>'
            . '<section class="panel"><h2>Health check</h2><pre>' . $healthHtml . '</pre></section></main></body></html>';
    }
}
