<?php

declare(strict_types=1);

use Reklamova\Cms\Database\ConnectionFactory;
use Reklamova\Cms\Modules\Knowledge\KnowledgeRepository;

if (!function_exists('knowledge_repository')) {
    function knowledge_repository(): ?KnowledgeRepository
    {
        global $container;
        require_once __DIR__ . '/src/KnowledgeRepository.php';
        try {
            return new KnowledgeRepository((new ConnectionFactory($container))->make());
        } catch (Throwable) {
            return null;
        }
    }
}

if (!function_exists('knowledge_articles')) {
    function knowledge_articles(bool $publishedOnly = true, string $search = ''): array
    {
        return knowledge_repository()?->articles($publishedOnly, $search) ?? [];
    }
}

if (!function_exists('knowledge_categories')) {
    function knowledge_categories(): array
    {
        return knowledge_repository()?->categories() ?? [];
    }
}
