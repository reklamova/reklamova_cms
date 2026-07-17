<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy;

final class PrivacyAuditLogger
{
    public function __construct(private PrivacyRepository $repository)
    {
    }

    public function log(?array $actor, string $action, string $entityType, ?int $entityId, mixed $before, mixed $after): void
    {
        $this->repository->audit($actor['id'] ?? null, $action, $entityType, $entityId, $before, $after);
    }
}
