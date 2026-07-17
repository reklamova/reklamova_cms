<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy;

final class FormConsentService
{
    public function __construct(private PrivacyRepository $repository)
    {
    }

    public function activeClause(string $type): ?array
    {
        foreach ($this->repository->formClauses() as $clause) {
            if ($clause['type'] === $type && (int) $clause['is_active'] === 1) {
                return $clause;
            }
        }

        return null;
    }
}
