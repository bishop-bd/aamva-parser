<?php

declare(strict_types=1);

namespace AamvaParser\Tests;

use AamvaParser\AamvaStandard;
use AamvaParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NationwideSyntheticTest extends TestCase
{
    private const JURISDICTIONS = [
        'AL', 'AK', 'AZ', 'AR', 'CA', 'CO', 'CT', 'DE', 'DC', 'FL', 'GA', 'HI', 'ID',
        'IL', 'IN', 'IA', 'KS', 'KY', 'LA', 'ME', 'MD', 'MA', 'MI', 'MN', 'MS', 'MO',
        'MT', 'NE', 'NV', 'NH', 'NJ', 'NM', 'NY', 'NC', 'ND', 'OH', 'OK', 'OR', 'PA',
        'RI', 'SC', 'SD', 'TN', 'TX', 'UT', 'VT', 'VA', 'WA', 'WV', 'WI', 'WY',
    ];

    #[DataProvider('jurisdictionAndCardTypeProvider')]
    public function testCurrentStandardStructureForEveryJurisdiction(
        string $jurisdiction,
        string $cardType,
    ): void {
        $document = (new Parser())->parseDocument($this->payload(
            $cardType,
            10,
            "DCSMOTORIST\nDACJANE\nDAJ{$jurisdiction}\nDAQSYNTHETIC-{$jurisdiction}-{$cardType}\n",
        ));

        $this->assertSame($jurisdiction, $document->normalized()['state']);
        $this->assertSame("SYNTHETIC-{$jurisdiction}-{$cardType}", $document->value('DAQ'));
        $this->assertSame([$cardType], array_column($document->subfiles(), 'type'));
        $this->assertSame(2025, $document->metadata()['standardPublicationYear']);
        $this->assertSame([], $document->warnings());
    }

    #[DataProvider('versionProvider')]
    public function testEveryRecognizedAamvaHeaderGeneration(int $version, int $publicationYear): void
    {
        $document = (new Parser())->parseDocument($this->payload(
            'DL',
            $version,
            "DCSMOTORIST\nDACJANE\nDAJTX\nDAQVERSION-{$version}\n",
        ));

        $this->assertSame($version, $document->metadata()['aamvaVersion']);
        $this->assertSame($publicationYear, $document->metadata()['standardPublicationYear']);
        $this->assertSame([], $document->warnings());
    }

    public function testSupportMatrixMatchesTheNationwideSyntheticSuite(): void
    {
        $matrix = file_get_contents(__DIR__ . '/../docs/support-matrix.md');
        $this->assertNotFalse($matrix);
        preg_match_all(
            '/^\| [^|]+ \| ([A-Z]{2}) \| Synthetic \| Synthetic \| Pending \|\r?$/m',
            $matrix,
            $matches,
        );

        $documented = $matches[1];
        sort($documented);
        $expected = self::JURISDICTIONS;
        sort($expected);

        $this->assertCount(51, $documented);
        $this->assertSame($expected, $documented);
    }

    /** @return iterable<string, array{string, string}> */
    public static function jurisdictionAndCardTypeProvider(): iterable
    {
        foreach (self::JURISDICTIONS as $jurisdiction) {
            foreach (['DL', 'ID'] as $cardType) {
                yield "{$jurisdiction}-{$cardType}" => [$jurisdiction, $cardType];
            }
        }
    }

    /** @return iterable<string, array{int, int}> */
    public static function versionProvider(): iterable
    {
        foreach (AamvaStandard::recognizedVersions() as $version) {
            yield sprintf('AAMVA-%02d', $version) => [
                $version,
                AamvaStandard::publicationYear($version),
            ];
        }
    }

    private function payload(string $type, int $version, string $fields): string
    {
        $subfile = $type . $fields;
        $prefix = "@\n\x1e\rANSI 636026" . sprintf('%02d0001', $version);
        $offset = strlen($prefix) + 11;
        $directory = $type . sprintf('%04d%04d', $offset, strlen($subfile));

        return $prefix . $directory . $subfile;
    }
}


