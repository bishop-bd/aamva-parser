<?php

declare(strict_types=1);

namespace AamvaParser\Tests;

use AamvaParser\DataHandler;
use AamvaParser\Parser;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class FixtureCorpusTest extends TestCase
{
    /** @param array<string, mixed> $fixture */
    #[DataProvider('fixtureProvider')]
    public function testFixture(array $fixture): void
    {
        foreach ([
            'jurisdiction', 'country', 'cardType', 'issueYears', 'aamvaVersion',
            'jurisdictionVersion', 'status', 'provenance', 'encoding', 'payload', 'expected',
        ] as $requiredKey) {
            $this->assertArrayHasKey($requiredKey, $fixture);
        }

        $this->assertContains($fixture['status'], ['synthetic', 'verified']);
        $payload = match ($fixture['encoding']) {
            'raw' => $fixture['payload'],
            'base64' => (new DataHandler())->base64Decode($fixture['payload']),
            'hex' => (new DataHandler())->fromByteString($fixture['payload']),
            default => $this->fail('Unsupported fixture encoding: ' . $fixture['encoding']),
        };

        $document = (new Parser())->parseDocument($payload);
        $this->assertSame($fixture['expected']['metadata'], $document->metadata());
        $this->assertSame($fixture['aamvaVersion'], $document->metadata()['aamvaVersion']);
        $this->assertSame($fixture['jurisdictionVersion'], $document->metadata()['jurisdictionVersion']);

        foreach ($fixture['expected']['normalized'] as $key => $value) {
            $this->assertSame($value, $document->normalized()[$key]);
        }
        foreach ($fixture['expected']['elements'] as $designator => $values) {
            $this->assertSame($values, $document->values($designator));
        }
        $this->assertSame(
            $fixture['expected']['subfileTypes'],
            array_column($document->subfiles(), 'type'),
        );
        $this->assertSame($fixture['expected']['warnings'], $document->warnings());
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function fixtureProvider(): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            __DIR__ . '/Fixtures',
            FilesystemIterator::SKIP_DOTS,
        ));

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'json') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertNotFalse($contents, 'Unable to read fixture ' . $file->getPathname());
            $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
            yield $file->getBasename('.json') => [$fixture];
        }
    }
}

