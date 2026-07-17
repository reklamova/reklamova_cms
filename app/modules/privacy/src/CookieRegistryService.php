<?php

declare(strict_types=1);

namespace Reklamova\Cms\Modules\Privacy;

final class CookieRegistryService
{
    public function __construct(private PrivacyRepository $repository)
    {
    }

    public function list(): array
    {
        return $this->repository->cookies();
    }
}
