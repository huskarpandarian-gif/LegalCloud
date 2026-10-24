<?php

namespace Tests\Unit;

use App\Domain\AI\VectorValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VectorValueTest extends TestCase
{
    public function test_it_serializes_finite_embedding_values_as_pgvector_input(): void
    {
        $this->assertSame('[0.1,0.2]', VectorValue::toPgVector([0.1, 0.2], 2));
    }

    public function test_it_rejects_embeddings_with_a_wrong_dimension(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VectorValue::toPgVector([0.1], 2);
    }

    #[DataProvider('invalidValues')]
    public function test_it_rejects_non_finite_or_non_numeric_values(array $values): void
    {
        $this->expectException(InvalidArgumentException::class);

        VectorValue::toPgVector($values, 1);
    }

    public static function invalidValues(): array
    {
        return [
            'nan' => [[NAN]],
            'infinity' => [[INF]],
            'string' => [['not-a-number']],
        ];
    }
}
