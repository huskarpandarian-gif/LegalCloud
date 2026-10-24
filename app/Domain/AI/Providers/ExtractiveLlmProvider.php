<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\LlmProvider;

class ExtractiveLlmProvider implements LlmProvider
{
    /** @var list<string> */
    private array $stopwords = [
        'que', 'qué', 'para', 'por', 'con', 'los', 'las', 'una', 'uno', 'del', 'como', 'esta', 'este',
        'son', 'sus', 'hay', 'más', 'mas', 'sobre', 'desde', 'entre', 'cuando', 'donde', 'dónde',
        'cual', 'cuál', 'cuales', 'cuáles', 'tiene', 'tienen', 'debe', 'deben', 'puede', 'pueden',
    ];

    public function answer(string $question, array $sources, string $mode = 'answer'): string
    {
        if ($mode === 'summary') {
            $parts = [];
            foreach ($sources as $index => $source) {
                $reference = $source['citation']['reference'] ?? $index + 1;
                $excerpt = $this->excerpt($source['content']);
                if ($excerpt !== '') {
                    $parts[] = $excerpt.' [S'.$reference.']';
                }
            }

            return $parts === []
                ? 'No hay fragmentos suficientes para resumir.'
                : "Resumen a partir de los documentos autorizados:\n\n".implode("\n\n", $parts);
        }

        $terms = $this->terms($question);
        $selected = [];
        foreach ($sources as $index => $source) {
            $reference = $source['citation']['reference'] ?? $index + 1;
            foreach ($this->sentences($source['content']) as $sentence) {
                $score = $this->score($sentence, $terms);
                if ($score === 0) {
                    continue;
                }
                $selected[$sentence] = ['score' => $score, 'sentence' => $sentence, 'reference' => $reference];
            }
        }

        if ($selected === []) {
            $source = $sources[0];
            $reference = $source['citation']['reference'] ?? 1;
            $excerpt = $this->excerpt($source['content']);

            return "Las fuentes no contienen una frase que responda de forma directa. El fragmento más cercano dice: {$excerpt} [S{$reference}]";
        }

        uasort($selected, fn (array $left, array $right) => $right['score'] <=> $left['score']);
        $lines = [];
        foreach (array_slice(array_values($selected), 0, 3) as $item) {
            $lines[] = $item['sentence'].' [S'.$item['reference'].']';
        }

        return implode(' ', $lines);
    }

    /** @return list<string> */
    private function terms(string $question): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            fn (string $term) => mb_strlen($term) >= 3 && ! in_array($term, $this->stopwords, true),
        )));
    }

    /** @param list<string> $terms */
    private function score(string $sentence, array $terms): int
    {
        if ($terms === []) {
            return 0;
        }

        $haystack = mb_strtolower($sentence);
        $score = 0;
        foreach ($terms as $term) {
            if (mb_stripos($haystack, $term) !== false) {
                $score++;
            }
        }

        return $score;
    }

    /** @return list<string> */
    private function sentences(string $content): array
    {
        $sentences = preg_split('/(?<=[\.\!\?])\s+/u', trim($content)) ?: [];
        $sentences = array_values(array_filter(array_map(
            fn (string $sentence) => trim($sentence),
            $sentences,
        ), fn (string $sentence) => $sentence !== ''));

        return $sentences === [] ? [trim($content)] : $sentences;
    }

    private function excerpt(string $content): string
    {
        $sentences = array_slice($this->sentences($content), 0, 2);

        return trim(implode(' ', $sentences));
    }
}
