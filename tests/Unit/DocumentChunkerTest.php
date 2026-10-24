<?php

namespace Tests\Unit;

use App\Domain\Documents\DocumentChunker;
use PHPUnit\Framework\TestCase;

class DocumentChunkerTest extends TestCase
{
    public function test_short_pages_stay_in_a_single_chunk(): void
    {
        $chunks = (new DocumentChunker())->fromPages(['El documento permite quince días.']);

        $this->assertCount(1, $chunks);
        $this->assertSame(0, $chunks[0]['chunk_index']);
        $this->assertSame(1, $chunks[0]['page']);
        $this->assertSame('El documento permite quince días.', $chunks[0]['content']);
    }

    public function test_windows_overlap_so_boundary_terms_remain_retrievable(): void
    {
        $words = [];
        for ($i = 1; $i <= 500; $i++) {
            $words[] = 'palabra'.$i;
        }

        $chunks = (new DocumentChunker(240, 40))->fromPages([implode(' ', $words)]);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertStringContainsString('palabra240', $chunks[0]['content']);
        $this->assertStringContainsString('palabra240', $chunks[1]['content']);
        $this->assertStringContainsString('palabra500', $chunks[array_key_last($chunks)]['content']);
    }
}
