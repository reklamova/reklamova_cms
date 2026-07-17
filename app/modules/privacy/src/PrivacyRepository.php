<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy;

use PDO;

final class PrivacyRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        $statement = $this->pdo->prepare('SELECT value FROM privacy_settings WHERE `key` = ? LIMIT 1');
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            return $default;
        }

        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function setSetting(string $key, mixed $value): void
    {
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $statement = $this->pdo->prepare(
            'INSERT INTO privacy_settings (`key`, value, created_at, updated_at)
             VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE value = VALUES(value), updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([$key, $encoded]);
    }

    public function settings(): array
    {
        $rows = $this->pdo->query('SELECT `key`, value FROM privacy_settings ORDER BY `key`')->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) $row['value'], true);
            $settings[$row['key']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row['value'];
        }

        return $settings;
    }

    public function categories(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM privacy_categories';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, id ASC';

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function categoryById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_categories WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function categoryBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_categories WHERE slug = ? LIMIT 1');
        $statement->execute([$slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function saveCategory(array $data): int
    {
        if (!empty($data['id'])) {
            $statement = $this->pdo->prepare(
                'UPDATE privacy_categories SET name = ?, short_description = ?, full_description = ?, is_active = ?, sort_order = ?, consent_mode_mapping_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $statement->execute([
                $data['name'],
                $data['short_description'],
                $data['full_description'],
                (int) ($data['is_active'] ?? 0),
                (int) ($data['sort_order'] ?? 0),
                $data['consent_mode_mapping_json'],
                (int) $data['id'],
            ]);

            return (int) $data['id'];
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO privacy_categories (slug, name, short_description, full_description, is_required, is_active, sort_order, consent_mode_mapping_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['slug'],
            $data['name'],
            $data['short_description'],
            $data['full_description'],
            (int) ($data['is_required'] ?? 0),
            (int) ($data['is_active'] ?? 1),
            (int) ($data['sort_order'] ?? 0),
            $data['consent_mode_mapping_json'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function scripts(bool $activeOnly = false): array
    {
        $sql = 'SELECT s.*, c.slug AS category_slug, c.name AS category_name
                FROM privacy_scripts s
                LEFT JOIN privacy_categories c ON c.id = s.category_id';
        if ($activeOnly) {
            $sql .= ' WHERE s.is_active = 1';
        }
        $sql .= ' ORDER BY s.priority ASC, s.id ASC';

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function script(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_scripts WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function saveScript(array $data, ?array $actor = null): int
    {
        if (!empty($data['id'])) {
            $before = $this->script((int) $data['id']);
            $statement = $this->pdo->prepare(
                'UPDATE privacy_scripts SET name = ?, type = ?, provider = ?, category_id = ?, placement = ?, scope_type = ?, scope_rules_json = ?, excluded_rules_json = ?, code = ?, external_url = ?, async_enabled = ?, defer_enabled = ?, priority = ?, is_active = ?, is_test_mode = ?, risk_acknowledged_at = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $statement->execute([
                $data['name'],
                $data['type'],
                $data['provider'],
                (int) $data['category_id'],
                $data['placement'],
                $data['scope_type'],
                $data['scope_rules_json'],
                $data['excluded_rules_json'],
                $data['code'],
                $data['external_url'],
                (int) ($data['async_enabled'] ?? 0),
                (int) ($data['defer_enabled'] ?? 0),
                (int) ($data['priority'] ?? 100),
                (int) ($data['is_active'] ?? 0),
                (int) ($data['is_test_mode'] ?? 0),
                $data['risk_acknowledged_at'],
                $actor['id'] ?? null,
                (int) $data['id'],
            ]);
            $id = (int) $data['id'];
            $this->addScriptVersion($id, $data, $actor);
            $this->audit($actor['id'] ?? null, 'privacy_script.updated', 'privacy_script', $id, $before, $data);

            return $id;
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO privacy_scripts (name, type, provider, category_id, placement, scope_type, scope_rules_json, excluded_rules_json, code, external_url, async_enabled, defer_enabled, priority, is_active, is_test_mode, risk_acknowledged_at, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['name'],
            $data['type'],
            $data['provider'],
            (int) $data['category_id'],
            $data['placement'],
            $data['scope_type'],
            $data['scope_rules_json'],
            $data['excluded_rules_json'],
            $data['code'],
            $data['external_url'],
            (int) ($data['async_enabled'] ?? 0),
            (int) ($data['defer_enabled'] ?? 0),
            (int) ($data['priority'] ?? 100),
            (int) ($data['is_active'] ?? 0),
            (int) ($data['is_test_mode'] ?? 0),
            $data['risk_acknowledged_at'],
            $actor['id'] ?? null,
            $actor['id'] ?? null,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->addScriptVersion($id, $data, $actor);
        $this->audit($actor['id'] ?? null, 'privacy_script.created', 'privacy_script', $id, null, $data);

        return $id;
    }

    public function addScriptVersion(int $scriptId, array $data, ?array $actor = null): void
    {
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(version_number), 0) + 1 FROM privacy_script_versions WHERE script_id = ?');
        $statement->execute([$scriptId]);
        $version = (int) $statement->fetchColumn();

        $settings = [
            'type' => $data['type'] ?? 'custom',
            'provider' => $data['provider'] ?? '',
            'category_id' => (int) ($data['category_id'] ?? 0),
            'placement' => $data['placement'] ?? 'body_end',
            'scope_type' => $data['scope_type'] ?? 'global',
            'scope_rules_json' => $data['scope_rules_json'] ?? '[]',
            'excluded_rules_json' => $data['excluded_rules_json'] ?? '[]',
            'async_enabled' => (bool) ($data['async_enabled'] ?? false),
            'defer_enabled' => (bool) ($data['defer_enabled'] ?? false),
            'priority' => (int) ($data['priority'] ?? 100),
            'settings' => $data['settings'] ?? [],
        ];

        $insert = $this->pdo->prepare(
            'INSERT INTO privacy_script_versions (script_id, version_number, code, external_url, settings_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([
            $scriptId,
            $version,
            $data['code'] ?? '',
            $data['external_url'] ?? null,
            json_encode($settings, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $actor['id'] ?? null,
        ]);
    }

    public function scriptVersions(int $scriptId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_script_versions WHERE script_id = ? ORDER BY version_number DESC');
        $statement->execute([$scriptId]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function restoreScriptVersion(int $scriptId, int $versionId, ?array $actor = null): void
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_script_versions WHERE id = ? AND script_id = ? LIMIT 1');
        $statement->execute([$versionId, $scriptId]);
        $version = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new \RuntimeException('Nie znaleziono wersji skryptu.');
        }

        $settings = json_decode((string) $version['settings_json'], true) ?: [];
        $script = $this->script($scriptId);
        if (!$script) {
            throw new \RuntimeException('Nie znaleziono skryptu.');
        }

        $data = array_merge($script, [
            'id' => $scriptId,
            'code' => (string) $version['code'],
            'external_url' => $version['external_url'],
            'type' => $settings['type'] ?? $script['type'],
            'provider' => $settings['provider'] ?? $script['provider'],
            'category_id' => (int) ($settings['category_id'] ?? $script['category_id']),
            'placement' => $settings['placement'] ?? $script['placement'],
            'scope_type' => $settings['scope_type'] ?? $script['scope_type'],
            'scope_rules_json' => $settings['scope_rules_json'] ?? $script['scope_rules_json'],
            'excluded_rules_json' => $settings['excluded_rules_json'] ?? $script['excluded_rules_json'],
            'async_enabled' => (int) ($settings['async_enabled'] ?? $script['async_enabled']),
            'defer_enabled' => (int) ($settings['defer_enabled'] ?? $script['defer_enabled']),
            'priority' => (int) ($settings['priority'] ?? $script['priority']),
            'settings' => $settings['settings'] ?? [],
            'risk_acknowledged_at' => $script['risk_acknowledged_at'],
        ]);

        $this->saveScript($data, $actor);
        $this->audit($actor['id'] ?? null, 'privacy_script.rollback', 'privacy_script', $scriptId, $script, ['version_id' => $versionId]);
    }

    public function documents(): array
    {
        return $this->pdo->query('SELECT * FROM privacy_documents ORDER BY type ASC, version DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function documentBySlug(string $slug): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_documents WHERE slug = ? AND status = "published" ORDER BY version DESC, id DESC LIMIT 1');
        $statement->execute([$slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        $statement = $this->pdo->prepare('SELECT * FROM privacy_documents WHERE slug = ? ORDER BY version DESC, id DESC LIMIT 1');
        $statement->execute([$slug]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function saveDocument(array $data, ?array $actor = null): int
    {
        if (!empty($data['id'])) {
            $before = $this->document((int) $data['id']);
            $statement = $this->pdo->prepare(
                'UPDATE privacy_documents SET type = ?, title = ?, slug = ?, content = ?, version = ?, status = ?, published_at = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $statement->execute([
                $data['type'],
                $data['title'],
                $data['slug'],
                $data['content'],
                (int) $data['version'],
                $data['status'],
                $data['published_at'],
                $actor['id'] ?? null,
                (int) $data['id'],
            ]);
            $this->audit($actor['id'] ?? null, 'privacy_document.updated', 'privacy_document', (int) $data['id'], $before, $data);

            return (int) $data['id'];
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO privacy_documents (type, title, slug, content, version, status, published_at, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['type'],
            $data['title'],
            $data['slug'],
            $data['content'],
            (int) $data['version'],
            $data['status'],
            $data['published_at'],
            $actor['id'] ?? null,
            $actor['id'] ?? null,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit($actor['id'] ?? null, 'privacy_document.created', 'privacy_document', $id, null, $data);

        return $id;
    }

    public function document(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_documents WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function formClauses(): array
    {
        return $this->pdo->query('SELECT * FROM privacy_form_clauses ORDER BY type ASC, version DESC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveFormClause(array $data, ?array $actor = null): int
    {
        if (!empty($data['id'])) {
            $before = $this->formClause((int) $data['id']);
            $statement = $this->pdo->prepare(
                'UPDATE privacy_form_clauses SET name = ?, type = ?, content = ?, version = ?, is_active = ?, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $statement->execute([$data['name'], $data['type'], $data['content'], (int) $data['version'], (int) ($data['is_active'] ?? 0), $actor['id'] ?? null, (int) $data['id']]);
            $this->audit($actor['id'] ?? null, 'privacy_form_clause.updated', 'privacy_form_clause', (int) $data['id'], $before, $data);

            return (int) $data['id'];
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO privacy_form_clauses (name, type, content, version, is_active, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$data['name'], $data['type'], $data['content'], (int) $data['version'], (int) ($data['is_active'] ?? 1), $actor['id'] ?? null, $actor['id'] ?? null]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit($actor['id'] ?? null, 'privacy_form_clause.created', 'privacy_form_clause', $id, null, $data);

        return $id;
    }

    public function formClause(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_form_clauses WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function cookies(): array
    {
        return $this->pdo->query(
            'SELECT r.*, c.slug AS category_slug, c.name AS category_name
             FROM privacy_cookie_registry r
             LEFT JOIN privacy_categories c ON c.id = r.category_id
             ORDER BY r.provider ASC, r.name ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveCookie(array $data, ?array $actor = null): int
    {
        if (!empty($data['id'])) {
            $before = $this->cookie((int) $data['id']);
            $statement = $this->pdo->prepare(
                'UPDATE privacy_cookie_registry SET name = ?, provider = ?, category_id = ?, purpose = ?, duration = ?, domain = ?, source_script_id = ?, is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            );
            $statement->execute([$data['name'], $data['provider'], (int) $data['category_id'], $data['purpose'], $data['duration'], $data['domain'], $data['source_script_id'] ?: null, (int) ($data['is_active'] ?? 0), (int) $data['id']]);
            $this->audit($actor['id'] ?? null, 'privacy_cookie.updated', 'privacy_cookie', (int) $data['id'], $before, $data);

            return (int) $data['id'];
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO privacy_cookie_registry (name, provider, category_id, purpose, duration, domain, source_script_id, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$data['name'], $data['provider'], (int) $data['category_id'], $data['purpose'], $data['duration'], $data['domain'], $data['source_script_id'] ?: null, (int) ($data['is_active'] ?? 1)]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit($actor['id'] ?? null, 'privacy_cookie.created', 'privacy_cookie', $id, null, $data);

        return $id;
    }

    public function cookie(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_cookie_registry WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function logConsent(array $data): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO privacy_consents (consent_uuid, consent_version, categories_json, consent_state_json, page_url, user_agent_hash, ip_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $data['consent_uuid'],
            $data['consent_version'],
            json_encode($data['categories'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($data['state'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $data['page_url'] ?? '',
            $data['user_agent_hash'] ?? '',
            $data['ip_hash'] ?? '',
        ]);
    }

    public function consentLog(int $limit = 100): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_consents ORDER BY created_at DESC LIMIT ?');
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function audit(?int $actorId, string $action, string $entityType, ?int $entityId, mixed $before, mixed $after): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO privacy_audit_log (actor_id, action, entity_type, entity_id, before_json, after_json)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $actorId,
            $action,
            $entityType,
            $entityId,
            $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function auditLog(int $limit = 100): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM privacy_audit_log ORDER BY created_at DESC LIMIT ?');
        $statement->bindValue(1, $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function purgeOldConsents(int $retentionDays): int
    {
        $statement = $this->pdo->prepare('DELETE FROM privacy_consents WHERE created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? DAY)');
        $statement->execute([$retentionDays]);

        return $statement->rowCount();
    }
}
