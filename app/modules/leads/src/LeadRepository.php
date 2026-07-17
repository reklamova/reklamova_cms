<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Leads;

use PDO;

final class LeadRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function create(array $payload, array $server = []): string
    {
        $publicId = bin2hex(random_bytes(12));
        $statement = $this->pdo->prepare(
            'INSERT INTO cms_leads
            (public_id, source, form_type, status, name, email, phone, company, message, page_url, consent_payload_json, payload_json, ip_hash, user_agent_hash)
            VALUES (?, ?, ?, "new", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $publicId,
            $this->text($payload['source'] ?? 'website', 120),
            $this->text($payload['form_type'] ?? $payload['type'] ?? 'contact', 120),
            $this->text($payload['name'] ?? null, 190),
            $this->email($payload['email'] ?? null),
            $this->text($payload['phone'] ?? null, 80),
            $this->text($payload['company'] ?? null, 190),
            $this->text($payload['message'] ?? null, 8000),
            $this->text($payload['page_url'] ?? $payload['page'] ?? null, 255),
            json_encode($payload['consent'] ?? $payload['privacy'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            json_encode($this->safePayload($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            hash('sha256', (string) ($server['REMOTE_ADDR'] ?? '')),
            hash('sha256', (string) ($server['HTTP_USER_AGENT'] ?? '')),
        ]);

        return $publicId;
    }

    public function all(): array
    {
        return $this->pdo->query('SELECT * FROM cms_leads ORDER BY created_at DESC, id DESC LIMIT 300')->fetchAll();
    }

    public function find(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM cms_leads WHERE id = ? LIMIT 1');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return is_array($row) ? $row : null;
    }

    public function updateStatus(int $id, string $status, ?int $actorId = null, string $note = ''): void
    {
        $lead = $this->find($id);
        if (!$lead) {
            return;
        }

        $allowed = ['new', 'in_progress', 'won', 'lost', 'spam', 'archived'];
        $status = in_array($status, $allowed, true) ? $status : 'new';

        $statement = $this->pdo->prepare('UPDATE cms_leads SET status = ?, note = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $statement->execute([$status, $note, $id]);

        $log = $this->pdo->prepare('INSERT INTO cms_lead_status_log (lead_id, old_status, new_status, actor_id, note) VALUES (?, ?, ?, ?, ?)');
        $log->execute([$id, $lead['status'], $status, $actorId, $note]);
    }

    private function safePayload(array $payload): array
    {
        unset($payload['_csrf'], $payload['password'], $payload['password_confirmation'], $payload['token']);

        return $payload;
    }

    private function text(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return mb_substr($value, 0, $limit, 'UTF-8');
    }

    private function email(mixed $value): ?string
    {
        $value = trim((string) $value);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_substr(strtolower($value), 0, 190, 'UTF-8') : null;
    }
}
