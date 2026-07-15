<?php

declare(strict_types=1);

namespace AamvaParser\Tests;

use AamvaParser\Parser;
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
}

