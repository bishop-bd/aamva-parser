<?php

declare(strict_types=1);

namespace AamvaParser\Tests;

use AamvaParser\Parser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormatCompatibilityTest extends TestCase
{
    #[DataProvider('formats')]
    public function testReferenceHeaderAndNameFormats(string $payload, int $version): void
    {
        $document = (new Parser())->parseDocument($payload);
        $result = $document->normalized();

        $this->assertSame($version, $document->metadata()['aamvaVersion']);
        $this->assertSame('Jane', $result['first']);
        $this->assertSame('Motorist', $result['last']);
        $this->assertSame('Quinn', $result['mid']);
        $this->assertSame('12 Example Rd.', $result['address']);
        $this->assertSame('A1A 1A1', $result['zip']);
        $this->assertSame('20303112', $result['fullEXP']);
        $this->assertSame('19921507', $result['fullDOB']);
        $this->assertSame(['DL'], array_column($document->subfiles(), 'type'));
    }

    public static function formats(): iterable
    {
        // Synthetic equivalents of the reference's v1 combined names,
        // v2/v3 DCT names, and v4-v12 DAC names. No issued-card data.
        foreach (range(1, 12) as $version) {
            $names = match ($version) {
                1 => 'DAAMotorist,Jane,Quinn,JR',
                2, 3 => "DCSMotorist\nDCTJane\nDADQuinn",
                default => "DCSMotorist\nDACJane\nDADQuinn",
            };
            $body = "DL{$names}\nDAG12 Example Rd.\nDAKA1A 1A1\nDBA12312030\nDBB07151992\r";
            $prefix = "@\n\x1e\rANSI 636026" . sprintf('%02d0001', $version);
            $raw = $prefix . 'DL' . sprintf('%04d%04d', strlen($prefix) + 10, strlen($body)) . $body;
            yield "v{$version}-raw" => [$raw, $version];
            yield "v{$version}-crlf" => [str_replace("\n", "\r\n", $raw), $version];
            yield "v{$version}-indented-no-indicator" => [str_replace("\n", "\n  ", substr($raw, 1)), $version];
            yield "v{$version}-base64" => [base64_encode($raw), $version];
        }
    }

    public function testCaseInsensitiveDesignatorsPreserveValuesAndUnknownElements(): void
    {
        $payload = "@\nansi 636026080001dl00310099dlDcSMcDonald\ndaCJane\nzzZKeep My Case\r";
        $document = (new Parser())->parseDocument($payload);
        $this->assertSame('ANSI', $document->metadata()['fileType']);
        $this->assertSame('McDonald', $document->normalized()['last']);
        $this->assertSame('Jane', $document->normalized()['first']);
        $this->assertSame('Keep My Case', $document->value('ZZZ'));
        $this->assertSame(['DL'], array_column($document->subfiles(), 'type'));
        $this->assertSame($document->normalized(), (new Parser())->parse(base64_encode($payload)));
        $this->assertSame('Jane', (new Parser())->parse(base64_encode('dacJane'))['first']);
    }

    public function testLegacyHeaderWithoutJurisdictionVersionKeepsTheFirstField(): void
    {
        foreach (['ANSI', 'AAMVA'] as $fileType) {
            $payload = "@\n{$fileType} 6360260101DL99990099DLDAAMotorist,Jane,Quinn,JR\nDBB07151992";
            $document = (new Parser())->parseDocument($payload);
            $this->assertSame($fileType, $document->metadata()['fileType']);
            $this->assertSame(1, $document->metadata()['aamvaVersion']);
            $this->assertNull($document->metadata()['jurisdictionVersion']);
            $this->assertSame('Jane', $document->normalized()['first']);
            $this->assertSame('Quinn', $document->normalized()['mid']);
        }
    }

    public function testCombinedNameFillsOnlyMissingPartsAndDoesNotIncludeSuffixInMiddleName(): void
    {
        $result = (new Parser())->parse("DACJanet\nDABDriver\nDAAMotorist,Jane,Quinn,JR");
        $this->assertSame('Janet', $result['first']);
        $this->assertSame('Driver', $result['last']);
        $this->assertSame('Quinn', $result['mid']);
        $this->assertSame('Quinn', (new Parser())->parse(base64_encode('DAAMotorist,Jane,Quinn,JR'))['mid']);
    }

    public function testMissingSubfilesCannotSplitOrDuplicateFields(): void
    {
        // The reference's v6-v12 fixtures declare ZT even when it is absent.
        // Put its bogus offset in an existing field to catch silent truncation.
        $prefix = "@\nANSI 636015120003DL00410001ZT00680007ZA99990007";
        $document = (new Parser())->parseDocument($prefix
            . "DLDCSMotorist\nDACJane\nDAG12 ZTZTA Avenue\nDAIExample\nDAJTX\nDAK77001");
        $this->assertSame('12 ZTZTA Avenue', $document->normalized()['address']);
        $this->assertSame('77001', $document->normalized()['zip']);
        $this->assertSame(['Jane'], $document->values('DAC'));
        $this->assertSame(['DL'], array_column($document->subfiles(), 'type'));
        $this->assertStringContainsString('Subfile ZT could not be located', implode(' ', $document->warnings()));
    }

    public function testMissingMiddleSubfileDoesNotHideTheFollowingSubfile(): void
    {
        $document = (new Parser())->parseDocument("@\nANSI 636026080003DL99990001ZT99990007ZA99990007"
            . "DLDCSMotorist\nDACJane\rZAZAAExtra\nDAG12 Example Rd.");
        $this->assertSame(['DL', 'ZA'], array_column($document->subfiles(), 'type'));
        $this->assertSame('Jane', $document->normalized()['first']);
        $this->assertSame('12 Example Rd.', $document->normalized()['address']);
        $this->assertSame('Extra', $document->value('ZAA'));
    }

    public function testDirectoryWithoutSubfileMarkerRecoversTheFields(): void
    {
        $document = (new Parser())->parseDocument("@\nANSI 636026080001DL99990050\nDCSMotorist\nDACJane");
        $this->assertSame('Motorist', $document->normalized()['last']);
        $this->assertSame('Jane', $document->normalized()['first']);
    }

    public function testJoinedScansDoNotMixPeopleOrParseLaterHeadersAsFields(): void
    {
        $first = $this->framedPayload('FIRST', 'Jane');
        $second = $this->framedPayload('SECOND', 'Alex');
        foreach (["\x04\x01\x02PRODUCT\x04\x01\x02\x03", "\x04", "\r"] as $separator) {
            foreach ([$first, $second, str_replace('ANSI ', 'AAMVA', $second)] as $next) {
                $raw = $first . $separator . $next . "\x04\0";
                foreach ([$raw, base64_encode($raw), implode(' ', str_split(bin2hex($raw), 2))] as $input) {
                    $document = (new Parser())->parseDocument($input);
                    $this->assertSame('Jane', $document->normalized()['first']);
                    $this->assertSame(['FIRST'], $document->values('DCS'));
                    $this->assertNull($document->value('ANS'));
                    $this->assertNull($document->value('AAM'));
                    $this->assertNull($document->value('PRO'));
                    $this->assertStringContainsString('first document', implode(' ', $document->warnings()));
                }
            }
        }
    }

    public function testScannerTerminatorExcludesTrailingProductData(): void
    {
        $document = (new Parser())->parseDocument($this->framedPayload('FIRST', 'Jane') . "\x04\x01\x02PRODUCT");
        $this->assertNull($document->value('PRO'));
        $this->assertSame('Jane', $document->normalized()['first']);
        $normal = (new Parser())->parseDocument($this->framedPayload('FIRST', 'Jane') . "\x04\0");
        $this->assertSame([], $normal->warnings());
    }

    public function testOffsetInsideRepeatedJurisdictionPrefixUsesTheRecordBoundary(): void
    {
        $dl = "DLDCSMOTORIST\nDACJANE\r";
        $zw = "ZWZWAWVDL_DL\r";
        $prefix = "@\n\x1e\rANSI 636061050002";
        $start = strlen($prefix) + 20;
        // Both zero- and one-based interpretations can point inside ZWZWA.
        foreach ([2, 3] as $shift) {
            $payload = $prefix . sprintf('DL%04d%04dZW%04d%04d', $start, strlen($dl), $start + strlen($dl) + $shift, strlen($zw))
                . $dl . $zw . "\x04\0";
            $document = (new Parser())->parseDocument($payload);
            $this->assertSame('WVDL_DL', $document->value('ZWA'));
            $this->assertNull($document->value('AWV'));
            $this->assertSame(['DL', 'ZW'], array_column($document->subfiles(), 'type'));
            $this->assertStringContainsString('recovered', implode(' ', $document->warnings()));
        }
    }

    private function framedPayload(string $last, string $first): string
    {
        $dl = "DLDCS{$last}\nDAC{$first}\r";
        $za = "ZAZAAEXTRA\r";
        $prefix = "@\n\x1e\rANSI 636026080002";
        $start = strlen($prefix) + 20;

        return $prefix . sprintf('DL%04d%04dZA%04d%04d', $start, strlen($dl), $start + strlen($dl), strlen($za)) . $dl . $za;
    }
}
