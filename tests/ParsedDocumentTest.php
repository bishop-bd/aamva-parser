<?php

declare(strict_types=1);

namespace AamvaParser\Tests;

use AamvaParser\AamvaStandard;
use AamvaParser\ParsedDocument;
use AamvaParser\Parser;
use JsonException;
use PHPUnit\Framework\TestCase;

final class ParsedDocumentTest extends TestCase
{
    public function testItExposesHeaderMetadataAndAllSubfiles(): void
    {
        $document = (new Parser())->parseDocument($this->payload([
            'DL' => "DLDCSMOTORIST\nDACJANE\nDAQD1234567\n",
            'ZA' => "ZAZAAJURISDICTION VALUE\n",
        ], version: 8, jurisdictionVersion: 1));

        $this->assertInstanceOf(ParsedDocument::class, $document);
        $this->assertSame([
            'complianceIndicator' => true,
            'fileType' => 'ANSI',
            'issuerIdentificationNumber' => '636026',
            'aamvaVersion' => 8,
            'standardPublicationYear' => 2016,
            'jurisdictionVersion' => 1,
            'declaredSubfileCount' => 2,
        ], $document->metadata());
        $this->assertSame(['DL', 'ZA'], array_column($document->subfiles(), 'type'));
        $this->assertSame('JURISDICTION VALUE', $document->value('zaa'));
        $this->assertSame([], $document->warnings());
    }

    public function testItPreservesRepeatedElementsAndNormalizesTheLastValue(): void
    {
        $document = (new Parser())->parseDocument(
            "DCSMOTORIST\r\nDACJANE\r\nDAGFIRST ADDRESS\r\nDAGSECOND ADDRESS\r\nDAQ1234",
        );

        $this->assertSame(['FIRST ADDRESS', 'SECOND ADDRESS'], $document->values('DAG'));
        $this->assertSame('SECOND ADDRESS', $document->value('DAG'));
        $this->assertSame('SECOND ADDRESS', $document->normalized()['address']);
        $this->assertNull($document->value('ZZZ'));
    }

    public function testItWarnsButStillParsesAFutureAamvaVersion(): void
    {
        $document = (new Parser())->parseDocument($this->payload([
            'DL' => "DLDCSMOTORIST\nDACJANE\nDAQ1234\n",
        ], version: 99));

        $this->assertSame(99, $document->metadata()['aamvaVersion']);
        $this->assertStringContainsString('version 99', $document->warnings()[0]);
        $this->assertSame('JANE', $document->normalized()['first']);
    }

    public function testItRecognizesEveryPublishedHeaderGeneration(): void
    {
        $this->assertSame(range(0, 10), AamvaStandard::recognizedVersions());
        $this->assertSame(2000, AamvaStandard::publicationYear(0));
        $this->assertSame(2025, AamvaStandard::publicationYear(10));
        $this->assertNull(AamvaStandard::publicationYear(99));
    }

    public function testFieldValuesContainingDlAreNotMistakenForSubfilePrefixes(): void
    {
        $document = (new Parser())->parseDocument(
            "DCSMOTORIST\nDACJANE\nDAG123 DLDCS VIEW\nDAQ1234",
        );

        $this->assertSame('MOTORIST', $document->normalized()['last']);
        $this->assertSame('123 DLDCS VIEW', $document->normalized()['address']);
        $this->assertSame(['DL'], array_column($document->subfiles(), 'type'));
    }

    public function testItRecoversFromAndReportsAnOutOfRangeDirectoryOffset(): void
    {
        $payload = $this->payload([
            'DL' => "DLDCSMOTORIST\nDACJANE\nDAQ1234\n",
        ], version: 8);
        $payload = preg_replace('/DL\d{4}/', 'DL9999', $payload, 1) ?? $payload;
        $document = (new Parser())->parseDocument($payload);

        $this->assertSame('JANE', $document->normalized()['first']);
        $this->assertStringContainsString('out-of-range offset', implode(' ', $document->warnings()));
        $this->assertStringContainsString('recovered', implode(' ', $document->warnings()));
    }

    public function testItAcceptsZeroBasedDirectoryOffsets(): void
    {
        $payload = $this->payload([
            'DL' => "DLDCSMOTORIST\nDACJANE\nDAQ1234\n",
        ], version: 8);
        $payload = preg_replace_callback(
            '/DL(\d{4})(\d{4})/',
            static fn (array $match): string => 'DL' . sprintf('%04d', (int) $match[1] - 1) . $match[2],
            $payload,
            1,
        ) ?? $payload;
        $document = (new Parser())->parseDocument($payload);

        $this->assertSame('JANE', $document->normalized()['first']);
        $this->assertSame([], $document->warnings());
    }

    public function testItReportsAndRecoversFromAnInaccurateIntermediateLength(): void
    {
        $payload = $this->payload([
            'DL' => "DLDCSMOTORIST\nDACJANE\n",
            'ZA' => "ZADAG123 MAIN ST\nDAJTX\n",
        ], version: 8);
        $payload = preg_replace('/(DL\d{4})\d{4}/', '${1}0001', $payload, 1) ?? $payload;
        $document = (new Parser())->parseDocument($payload);

        $this->assertSame('JANE', $document->normalized()['first']);
        $this->assertSame('123 MAIN ST', $document->normalized()['address']);
        $this->assertStringContainsString('inaccurate declared length', implode(' ', $document->warnings()));
    }

    /** @throws JsonException */
    public function testItSerializesWithoutDiscardingRawElements(): void
    {
        $document = (new Parser())->parseDocument("DCSMOTORIST\nDACJANE\nDAQ1234");
        $json = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('JANE', $json['normalized']['first']);
        $this->assertSame(['JANE'], $json['elements']['DAC']);
        $this->assertArrayHasKey('metadata', $json);
        $this->assertArrayHasKey('subfiles', $json);
    }

    /** @param array<string, string> $subfiles */
    private function payload(array $subfiles, int $version, int $jurisdictionVersion = 0): string
    {
        $prefix = "@\n\x1e\rANSI 636026"
            . sprintf('%02d%02d%02d', $version, $jurisdictionVersion, count($subfiles));
        $offset = strlen($prefix) + (count($subfiles) * 10) + 1;
        $directory = '';
        $body = '';

        foreach ($subfiles as $type => $subfile) {
            $directory .= $type . sprintf('%04d%04d', $offset, strlen($subfile));
            $body .= $subfile;
            $offset += strlen($subfile);
        }

        return $prefix . $directory . $body;
    }
}



