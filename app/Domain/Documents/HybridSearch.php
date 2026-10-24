<?php

namespace App\Domain\Documents;

use App\Domain\AI\VectorIndexService;

class HybridSearch
{
    public function __construct(
        private readonly VectorIndexService $vectors,
        private readonly DocumentTextSearch $text,
    ) {}

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function search(string $query, string $organizationId, array $filters, int $page, int $perPage): array
    {
        $window = min(500, max(100, $page * $perPage));
        $semantic = $this->vectors->search($query, $organizationId, $filters, 1, $window);
        $lexical = $this->text->search($query, $organizationId, $filters, 1, $window);
        $fused = ReciprocalRankFusion::fuse([$semantic['data'], $lexical['data']]);
        $total = count($fused);

        return [
            'data' => array_slice($fused, ($page - 1) * $perPage, $perPage),
            'meta' => [
                'currentPage' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }
}
