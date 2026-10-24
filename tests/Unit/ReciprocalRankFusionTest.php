<?php

namespace Tests\Unit;

use App\Domain\Documents\ReciprocalRankFusion;
use PHPUnit\Framework\TestCase;

class ReciprocalRankFusionTest extends TestCase
{
    public function test_a_document_present_in_both_rankings_outranks_a_single_list_hit(): void
    {
        $shared = ['document' => ['id' => 'shared'], 'excerpt' => 'semántico', 'page' => 2, 'score' => 0.2];
        $lexicalOnly = ['document' => ['id' => 'lexical'], 'excerpt' => 'léxico', 'page' => 1, 'score' => 0.9];
        $semanticOnly = ['document' => ['id' => 'semantic'], 'excerpt' => 'vector', 'page' => 4, 'score' => 0.99];

        $fused = ReciprocalRankFusion::fuse([
            [$semanticOnly, $shared],
            [$lexicalOnly, $shared],
        ]);

        $this->assertSame('shared', $fused[0]['document']['id']);
        $this->assertSame(1.0, $fused[0]['score']);
        $this->assertSame('semántico', $fused[0]['excerpt']);
        $this->assertNotContains('shared', array_column(array_column(array_slice($fused, 1), 'document'), 'id'));
    }
}
