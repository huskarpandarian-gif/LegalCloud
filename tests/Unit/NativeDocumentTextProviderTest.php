<?php

namespace Tests\Unit;

use App\Domain\AI\ProviderNotConfigured;
use App\Domain\AI\Providers\NativeDocumentTextProvider;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class NativeDocumentTextProviderTest extends TestCase
{
    public function test_it_reads_a_pdf_text_layer_without_inventing_content(): void
    {
        $path = $this->temporaryFile(<<<'PDF'
            %PDF-1.1
            1 0 obj
            << /Length 68 >>
            stream
            BT
            (Quince dias habiles) Tj
            ET
            endstream
            endobj
            %%EOF
            PDF);

        $result = (new NativeDocumentTextProvider())->extract($path, 'application/pdf');

        $this->assertStringContainsString('Quince dias habiles', $result['text']);
        $this->assertSame($result['text'], $result['pages'][0]);
        unlink($path);
    }

    public function test_it_reads_docx_paragraphs(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('PHP ZipArchive is not installed.');
        }

        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legalcloud-'.uniqid().'.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document><w:p><w:r><w:t>Plazo de quince días</w:t></w:r></w:p></w:document>');
        $zip->close();

        $result = (new NativeDocumentTextProvider())->extract(
            $path,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

        $this->assertStringContainsString('Plazo de quince días', $result['text']);
        unlink($path);
    }

    public function test_images_still_require_an_ocr_provider(): void
    {
        $path = $this->temporaryFile('not-an-image');

        $this->expectException(ProviderNotConfigured::class);
        try {
            (new NativeDocumentTextProvider())->extract($path, 'image/png');
        } finally {
            unlink($path);
        }
    }

    public function test_a_pdf_without_a_text_layer_is_not_indexed_as_empty_success(): void
    {
        $path = $this->temporaryFile("%PDF-1.4\n1 0 obj\n<< >>\nendobj\n%%EOF");

        $this->expectException(ProviderNotConfigured::class);
        try {
            (new NativeDocumentTextProvider())->extract($path, 'application/pdf');
        } finally {
            unlink($path);
        }
    }

    private function temporaryFile(string $contents): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'legalcloud-'.uniqid();
        file_put_contents($path, $contents);

        return $path;
    }
}
