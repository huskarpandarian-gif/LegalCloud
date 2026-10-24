<?php

namespace App\Domain\Documents;

class DocumentChunker
{
    public function __construct(
        private readonly int $wordsPerChunk = 240,
        private readonly int $overlapWords = 40,
    ) {}

    /**
     * Split each page into overlapping word windows.
     *
     * @param  array<int, string>  $pages
     * @return list<array{chunk_index: int, page: int, content: string}>
     */
    public function fromPages(array $pages): array
    {
        $chunks = [];
        $step = max(1, $this->wordsPerChunk - min($this->overlapWords, $this->wordsPerChunk - 1));

        foreach ($pages as $page => $content) {
            $words = preg_split('/\s+/u', trim($content)) ?: [];
            $words = array_values(array_filter($words, fn (string $word): bool => $word !== ''));
            if ($words === []) {
                continue;
            }

            $offset = 0;
            $index = 0;
            $total = count($words);
            while ($offset < $total) {
                $slice = array_slice($words, $offset, $this->wordsPerChunk);
                if ($slice === []) {
                    break;
                }

                $chunks[] = [
                    'chunk_index' => ((int) $page) * 10000 + $index,
                    'page' => ((int) $page) + 1,
                    'content' => implode(' ', $slice),
                ];
                $index++;
                if ($offset + $this->wordsPerChunk >= $total) {
                    break;
                }
                $offset += $step;
            }
        }

        return $chunks;
    }
}
