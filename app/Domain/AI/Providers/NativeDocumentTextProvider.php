<?php

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\OcrProvider;
use App\Domain\AI\ProviderNotConfigured;
use ZipArchive;

class NativeDocumentTextProvider implements OcrProvider
{
    public function extract(string $path, string $mimeType): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new ProviderNotConfigured('The document could not be read for text extraction.');
        }

        $text = match (true) {
            $this->isImage($mimeType) => throw new ProviderNotConfigured('No image OCR provider has been configured.'),
            $this->isPdf($mimeType, $contents) => $this->pdf($contents),
            $this->isDocx($mimeType, $contents) => $this->docx($path),
            $this->isLegacyWord($mimeType) => throw new ProviderNotConfigured('Legacy Word .doc files require an OCR or conversion provider.'),
            str_starts_with($mimeType, 'text/') => $contents,
            default => throw new ProviderNotConfigured('No text extractor is configured for this file type.'),
        };

        $text = $this->normalize($text);
        if ($text === '') {
            throw new ProviderNotConfigured('The file has no extractable text layer.');
        }

        $pages = preg_split("/\f/u", $text) ?: [$text];
        $pages = array_values(array_filter(
            array_map(fn (string $page): string => trim($page), $pages),
            fn (string $page): bool => $page !== '',
        ));

        return [
            'text' => implode("\n\n", $pages),
            'pages' => $pages,
        ];
    }

    private function isImage(string $mimeType): bool
    {
        return str_starts_with($mimeType, 'image/');
    }

    private function isLegacyWord(string $mimeType): bool
    {
        return $mimeType === 'application/msword';
    }

    private function isDocx(string $mimeType, string $contents): bool
    {
        return str_contains($mimeType, 'wordprocessingml')
            || str_contains($mimeType, 'officedocument')
            || str_starts_with($contents, "PK\x03\x04");
    }

    private function isPdf(string $mimeType, string $contents): bool
    {
        return str_contains($mimeType, 'pdf') || str_starts_with($contents, '%PDF');
    }

    private function docx(string $path): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new ProviderNotConfigured('PHP ZipArchive is required to read DOCX documents.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new ProviderNotConfigured('The DOCX document could not be opened.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if (! is_string($xml) || $xml === '') {
            throw new ProviderNotConfigured('The DOCX document has no word/document.xml text.');
        }

        $xml = preg_replace('/<w:tab\/>/', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:p>/', "\n", $xml) ?? $xml;

        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function pdf(string $contents): string
    {
        $buffers = [$contents];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $contents, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded === false) {
                    $decoded = @gzinflate($stream);
                }
                if (is_string($decoded) && $decoded !== '') {
                    $buffers[] = $decoded;
                }
            }
        }

        $texts = [];
        foreach ($buffers as $buffer) {
            if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)\s*Tj/s', $buffer, $tokens)) {
                foreach ($tokens[0] as $token) {
                    $literal = preg_replace('/\s*Tj$/s', '', $token) ?? $token;
                    $texts[] = $this->pdfLiteral($literal);
                }
            }
            if (preg_match_all('/\[(.*?)\]\s*TJ/s', $buffer, $arrays)) {
                foreach ($arrays[1] as $array) {
                    if (! preg_match_all('/\((?:\\\\.|[^\\\\)])*\)|<([0-9A-Fa-f\s]+)>/', $array, $parts)) {
                        continue;
                    }
                    foreach ($parts[0] as $part) {
                        $texts[] = str_starts_with($part, '<') ? $this->pdfHex($part) : $this->pdfLiteral($part);
                    }
                }
            }
        }

        $texts = array_values(array_filter($texts, fn (string $text): bool => preg_match('/\p{L}/u', $text) === 1));

        return implode(' ', $texts);
    }

    private function pdfLiteral(string $literal): string
    {
        $inner = $literal;
        if (str_starts_with($inner, '(') && str_ends_with($inner, ')')) {
            $inner = substr($inner, 1, -1);
        }

        $inner = preg_replace_callback('/\\\\([0-7]{1,3})/', fn (array $match): string => chr(octdec($match[1])), $inner) ?? $inner;

        return str_replace(
            ['\n', '\r', '\t', '\(', '\)', '\\\\'],
            ["\n", "\r", "\t", '(', ')', '\\'],
            $inner,
        );
    }

    private function pdfHex(string $hex): string
    {
        $digits = preg_replace('/[^0-9A-Fa-f]/', '', $hex) ?? '';
        if ($digits === '' || strlen($digits) % 2 !== 0) {
            return '';
        }

        $bytes = hex2bin($digits);

        return is_string($bytes) ? $bytes : '';
    }

    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0E-\x1F\x7F]/', '', $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
