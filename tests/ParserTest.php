<?php

declare(strict_types=1);

namespace AamvaParser\Tests;

use AamvaParser\DataHandler;
use AamvaParser\Parser;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    public function testParserCanBeInstantiated(): void
    {
        $parser = new Parser();

        $this->assertInstanceOf(Parser::class, $parser);
    }

    public function testParseMethodExists(): void
    {
        $this->assertTrue(method_exists(Parser::class, 'parse'));
    }

    public function testItParsesTheFormattedSample(): void
    {
        $data = <<<'AAMVA'
@ANSI
USA
DL
DCSDOE
DACJANE
DADQUINN
DAG123 FAKE ST
DAH
DAIEXAMPLETOWN
DAJTX
DAK750010123
DBA20301231
DBB19920715
AAMVA;

        $result = (new Parser())->parse($data);

        $this->assertSame([
            'first' => 'JANE',
            'last' => 'DOE',
            'mid' => 'QUINN',
            'address' => '123 FAKE ST',
            'address2' => '',
            'city' => 'EXAMPLETOWN',
            'state' => 'TX',
            'zip' => '750010123',
            'expMM' => '12',
            'expDD' => '31',
            'expYYYY' => '2030',
            'dobMM' => '07',
            'dobDD' => '15',
            'dobYYYY' => '1992',
            'fullEXP' => '20303112',
        ], $result);
    }

    public function testItParsesTheCompleteHexadecimalScannerFrameAndItsBase64Form(): void
    {
        $hex = <<<'HEX'
40 0A 1E 0D 41 4E 53 49 20 36 33 36 30 30 35 30 39 30 30 30 31 44 4C 30 30 33 31 30 32 38 34 44 4C 44 43 41 44 0A 44 43 42 41 0A 44 43 44 4E 4F 4E 45 0A 44 42 41 31 32 33 31 32 30 33 30 0A 44 43 53 44 4F 45 0A 44 41 43 4A 41 4E 45 0A 44 41 44 51 55 49 4E 4E 0A 44 42 44 30 31 30 31 32 30 32 35 0A 44 42 42 30 37 31 35 31 39 39 32 0A 44 42 43 32 0A 44 41 59 47 52 4E 0A 44 41 55 30 36 35 20 69 6E 0A 44 41 47 31 32 33 20 46 41 4B 45 20 53 54 0A 44 41 49 45 58 41 4D 50 4C 45 54 4F 57 4E 0A 44 41 4A 54 58 0A 44 41 4B 37 35 30 30 31 30 31 32 33 0A 44 41 51 46 31 32 33 34 35 36 37 0A 44 43 46 30 30 30 30 30 30 30 30 30 30 30 30 30 30 30 30 30 30 0A 44 43 47 55 53 41 0A 44 44 45 55 0A 44 44 46 55 0A 44 44 47 55 0A 44 43 4C 57 0A 44 43 4D 2A 2A 2A 2A 0A 44 43 52 41 20 2D 20 43 6F 72 72 65 63 74 69 76 65 20 4C 65 6E 73 0A 44 44 41 46 0A 44 44 42 30 31 30 31 32 30 32 30 0A 44 41 57 31 34 30 0D 04 00 42
HEX;
        $raw = (new DataHandler())->fromByteString($hex);
        $parser = new Parser();

        $expected = [
            'first' => 'JANE',
            'last' => 'DOE',
            'mid' => 'QUINN',
            'address' => '123 FAKE ST',
            'address2' => '',
            'city' => 'EXAMPLETOWN',
            'state' => 'TX',
            'zip' => '750010123',
            'expMM' => '12',
            'expDD' => '31',
            'expYYYY' => '2030',
            'dobMM' => '07',
            'dobDD' => '15',
            'dobYYYY' => '1992',
            'fullEXP' => '20303112',
        ];

        $this->assertSame($expected, $parser->parse($hex));
        $this->assertSame($expected, $parser->parse(base64_encode($raw)));
    }

    public function testItParsesAnAnsiDirectoryAndMmDdYyyyDates(): void
    {
        $data = $this->payload([
            'DL' => "DLDCSMOTORIST\nDACJANE\nDADNONE\nDBA07052030\nDBB12251990\n",
        ]);

        $result = (new Parser())->parse($data);

        $this->assertSame('JANE', $result['first']);
        $this->assertSame('MOTORIST', $result['last']);
        $this->assertSame('', $result['mid']);
        $this->assertSame('07', $result['expMM']);
        $this->assertSame('05', $result['expDD']);
        $this->assertSame('2030', $result['expYYYY']);
        $this->assertSame('20300507', $result['fullEXP']);
        $this->assertSame('12', $result['dobMM']);
        $this->assertSame('25', $result['dobDD']);
        $this->assertSame('1990', $result['dobYYYY']);
    }

    public function testItMergesRequestedDataFromMultipleSubfiles(): void
    {
        $dl = "DLDCSMOTORIST\nDACJANE\n";
        $za = "ZADAG123 MAIN ST\nDAIANYTOWN\nDAJCA\nDAK90210\n";
        $result = (new Parser())->parse($this->payload(['DL' => $dl, 'ZA' => $za]));

        $this->assertSame('JANE', $result['first']);
        $this->assertSame('MOTORIST', $result['last']);
        $this->assertSame('123 MAIN ST', $result['address']);
        $this->assertSame('ANYTOWN', $result['city']);
        $this->assertSame('CA', $result['state']);
        $this->assertSame('90210', $result['zip']);
    }

    public function testItHandlesBomNoiseAndPipeDelimitedScannerOutput(): void
    {
        $data = "\xEF\xBB\xBFscanner noise\n@|\x1e|ANSI 636002040001DL00390052"
            . '|DLDCS SMITH |DAC ROBERT|DAK021080000|DCGUSA|';

        $result = (new Parser())->parse($data);

        $this->assertSame('SMITH', $result['last']);
        $this->assertSame('ROBERT', $result['first']);
        $this->assertSame('021080000', $result['zip']);
        $this->assertSame('', $result['expMM']);
        $this->assertSame('', $result['fullEXP']);
    }

    public function testItHandlesMixedAndRepeatedScannerSeparators(): void
    {
        $data = "@|\x1e\rANSI 636026100001DL00320047"
            . "|\nDLDCSMOTORIST||\x1eDACJANE\r\nDAJTX|DAQ1234|\x04\0";

        $result = (new Parser())->parse($data);

        $this->assertSame('MOTORIST', $result['last']);
        $this->assertSame('JANE', $result['first']);
        $this->assertSame('TX', $result['state']);
    }

    public function testItUsesLegacyCombinedAndGivenNameFields(): void
    {
        $combined = (new Parser())->parse("DAADOE,JANE,ELIZABETH\r\nDAQ1234");
        $given = (new Parser())->parse("DABSMITH\r\nDCTJOHN PAUL\r\nDAQ1234");

        $this->assertSame('DOE', $combined['last']);
        $this->assertSame('JANE', $combined['first']);
        $this->assertSame('ELIZABETH', $combined['mid']);
        $this->assertSame('SMITH', $given['last']);
        $this->assertSame('JOHN', $given['first']);
        $this->assertSame('PAUL', $given['mid']);
    }

    public function testItReturnsEveryKeyWhenOptionalDataIsMissingOrDatesAreInvalid(): void
    {
        $result = (new Parser())->parse("DCSMOTORIST\r\nDACJANE\r\nDBA02312030\r\nDAQ1234");

        $this->assertSame([
            'first', 'last', 'mid', 'address', 'address2', 'city', 'state', 'zip',
            'expMM', 'expDD', 'expYYYY', 'dobMM', 'dobDD', 'dobYYYY', 'fullEXP',
        ], array_keys($result));
        $this->assertSame('', $result['address2']);
        $this->assertSame('', $result['expMM']);
        $this->assertSame('', $result['expDD']);
        $this->assertSame('', $result['expYYYY']);
        $this->assertSame('', $result['fullEXP']);
    }

    public function testItRejectsEmptyAndNonAamvaData(): void
    {
        $parser = new Parser();

        foreach (['', " \r\n\t", 'not a barcode'] as $invalidData) {
            try {
                $parser->parse($invalidData);
                $this->fail('Expected malformed data to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @param array<string, string> $subfiles */
    private function payload(array $subfiles): string
    {
        $prefix = "@\n\x1e\rANSI 6360260801" . sprintf('%02d', count($subfiles));
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

