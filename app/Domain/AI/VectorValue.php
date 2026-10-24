<?php

namespace App\Domain\AI;

use InvalidArgumentException;
use JsonException;

class VectorValue
{
    /** @param list<float|int> $values
     * @throws JsonException
     */
    public static function toPgVector(array $values, int $dimensions): string
    {
        if ($dimensions < 1 || $dimensions > 2000 || count($values) !== $dimensions) {
            throw new InvalidArgumentException('Embedding dimensions do not match the configured pgvector dimension.');
        }

        foreach ($values as $value) {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Embeddings must contain only finite numeric values.');
            }
        }

        return json_encode(array_map('floatval', array_values($values)), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }
}
