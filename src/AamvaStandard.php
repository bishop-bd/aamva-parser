<?php

declare(strict_types=1);

namespace AamvaParser;

/** Identifies published AAMVA DL/ID Card Design Standard generations. */
final class AamvaStandard
{
    /** @var array<int, int> Header version => publication year. */
    private const PUBLICATION_YEARS = [
        0 => 2000,
        1 => 2003,
        2 => 2005,
        3 => 2009,
        4 => 2010,
        5 => 2011,
        6 => 2012,
        7 => 2013,
        8 => 2016,
        9 => 2020,
        10 => 2025,
    ];

    public static function publicationYear(int $headerVersion): ?int
    {
        return self::PUBLICATION_YEARS[$headerVersion] ?? null;
    }

    public static function isRecognized(int $headerVersion): bool
    {
        return isset(self::PUBLICATION_YEARS[$headerVersion]);
    }

    /** @return list<int> */
    public static function recognizedVersions(): array
    {
        return array_keys(self::PUBLICATION_YEARS);
    }
}
