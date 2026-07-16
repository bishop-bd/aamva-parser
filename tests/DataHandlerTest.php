<?php

declare(strict_types=1);

namespace AamvaParser\Tests;

use AamvaParser\DataHandler;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DataHandlerTest extends TestCase
{
    public function testItConvertsSpaceAndCommaSeparatedHexadecimalBytes(): void
    {
        $handler = new DataHandler();

        $this->assertSame("@\n\x1e\rANSI", $handler->fromByteString('40 0A 1E 0D 41 4E 53 49'));
        $this->assertSame('@ANSI', $handler->fromByteString('0x40, 0x41, 0x4E, 0x53, 0x49'));
    }

    public function testItDecodesPaddedAndUnpaddedBase64(): void
    {
        $handler = new DataHandler();
        $raw = "@\n\x1e\rANSI";

        $this->assertSame($raw, $handler->base64Decode(base64_encode($raw)));
        $this->assertSame($raw, $handler->base64Decode(rtrim(base64_encode($raw), '=')));
    }

    public function testItAutoDecodesLayeredScannerData(): void
    {
        $handler = new DataHandler();
        $raw = "@\n\x1e\rANSI 636005090001DL00310020DLDCSDOE\n";
        $hex = implode(' ', str_split(strtoupper(bin2hex(base64_encode($raw))), 2));

        $this->assertSame($raw, $handler->decode($hex));
        $this->assertSame($raw, $handler->decode($raw));
    }

    public function testExplicitConversionsRejectMalformedValues(): void
    {
        $handler = new DataHandler();

        foreach ([
            fn (): string => $handler->fromByteString('40 0G'),
            fn (): string => $handler->fromByteString('40 A'),
            fn (): string => $handler->base64Decode('not!base64'),
            fn (): string => $handler->base64Decode('A'),
        ] as $conversion) {
            try {
                $conversion();
                $this->fail('Expected malformed encoded data to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

