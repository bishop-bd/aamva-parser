<?php

declare(strict_types=1);

namespace AamvaParser\Tests;

use AamvaParser\Parser;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InputValidationTest extends TestCase
{
    #[DataProvider('unsupportedInputs')]
    public function testItRejectsUnrelatedRecords(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Parser())->parseDocument($input);
    }

    public static function unsupportedInputs(): iterable
    {
        // Synthetic fixed-width credentials, not real names or identifiers.
        foreach (['NAR', 'NBR', 'NEF', 'NEP', 'NHI', 'NHJ', 'NHK', 'NHO', 'NIG', 'NJB', 'NJF', 'NJH'] as $prefix) {
            $raw = $prefix . 'SYNTHETIC123' . str_pad('Jane', 20) . str_pad('Motorist', 26)
                . "TEST0000SSGT  ME05EXAMPLE\x04\0C";
            yield "{$prefix}-raw" => [$raw];
            yield "{$prefix}-hex" => [implode(' ', str_split(bin2hex($raw), 2))];
            yield "{$prefix}-base64" => [base64_encode($raw)];
        }

        yield 'uppercase prose' => ['THIS IS NOT A BARCODE'];
        yield 'unknown elements only' => ["XYZFIRST\nZZZSECOND"];
        yield 'unrecognized D code' => ['DZZUNRELATED'];
        yield 'embedded known identifier' => ['NARSYNTHETICDACJANE'];
        yield 'embedded header word' => ['NARANSI SYNTHETIC'];
        yield 'indicator only' => ["@\nNARSYNTHETIC"];
        yield 'malformed header' => ["@\nANSI\nNARSYNTHETIC"];
    }

    public function testHeaderlessAamvaRetainsUnknownAndRepeatedElements(): void
    {
        foreach (["\n", "\r\n", '|'] as $separator) {
            $raw = implode($separator, ['XYZFIRST', 'idDaCJane', 'ZZZEXTRA', 'XYZSECOND']);
            foreach ([$raw, base64_encode($raw)] as $input) {
                $document = (new Parser())->parseDocument($input);
                $this->assertSame('Jane', $document->normalized()['first']);
                $this->assertSame(['FIRST', 'SECOND'], $document->values('XYZ'));
                $this->assertSame('EXTRA', $document->value('ZZZ'));
            }
        }
    }

    public function testDocumentNumberAloneIsRecognizedWithoutAddingNormalizedFields(): void
    {
        foreach (['DAQ', 'DBJ'] as $code) {
            foreach ([$code . 'SYNTHETIC', base64_encode($code . 'SYNTHETIC')] as $input) {
                $document = (new Parser())->parseDocument($input);
                $this->assertSame('SYNTHETIC', $document->value($code));
                $this->assertSame('', $document->normalized()['first']);
            }
        }
    }

    public function testStructuredHeaderCanContainOnlyUnknownElements(): void
    {
        $body = "DLXYZEXTRA\r";
        $prefix = "@\n\x1e\rANSI 636026080001";
        $raw = $prefix . sprintf('DL%04d%04d', strlen($prefix) + 10, strlen($body)) . $body;
        $document = (new Parser())->parseDocument($raw);
        $this->assertSame('EXTRA', $document->value('XYZ'));
        $this->assertSame([], $document->warnings());
    }
}
