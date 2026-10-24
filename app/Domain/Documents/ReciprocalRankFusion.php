<?php

namespace App\Domain\Documents;

class ReciprocalRankFusion
{
    public const K = 60;

    /**
     * Fuse ranked lists. Scores are relative: the best fused hit is 1.
     *
     * @param  list<list<array<string, mixed>>>  $rankings
     * @param  (callable(array<string, mixed>): string)|null  $key
     * @return list<array<string, mixed>>
     */
    public static function fuse(array $rankings, ?callable $key = null): array
    {
        $key ??= fn (array $hit): string => (string) ($hit['document']['id'] ?? '');
        $merged = [];

        foreach ($rankings as $ranking) {
            foreach (array_values($ranking) as $rank => $hit) {
                $id = $key($hit);
                if ($id === '') {
                    continue;
                }

                $contribution = 1 / (self::K + $rank + 1);
                if (! isset($merged[$id])) {
                    $hit['score'] = 0.0;
                    $hit['_bestRank'] = $rank;
                    $merged[$id] = $hit;
                } elseif ($rank < $merged[$id]['_bestRank']) {
                    $hit['score'] = $merged[$id]['score'];
                    $hit['_bestRank'] = $rank;
                    $merged[$id] = $hit;
                }

                $merged[$id]['score'] += $contribution;
            }
        }

        $items = array_values($merged);
        usort($items, fn (array $left, array $right): int => $right['score'] <=> $left['score']);
        $max = (float) ($items[0]['score'] ?? 0);

        foreach ($items as &$item) {
            unset($item['_bestRank']);
            $item['score'] = $max > 0 ? round($item['score'] / $max, 4) : 0.0;
        }
        unset($item);

        return $items;
    }
}
