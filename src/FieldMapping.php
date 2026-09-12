<?php

declare(strict_types=1);

namespace AamvaParser;

final class FieldMapping
{
    /**
     * Normalized name => AAMVA codes, in priority order.
     * Add a row here to include another field in the parser's output.
     * The first present code wins, including when its value is empty.
     *
     * @var array<string, list<string>>
     */
    public const FIELDS = [
        'first' => ['DAC'],
        'last' => ['DCS', 'DAB'],
        'mid' => ['DAD'],
        'address' => ['DAG'],
        'address2' => ['DAH'],
        'city' => ['DAI'],
        'state' => ['DAJ'],
        'zip' => ['DAK'],
    ];

    /**
     * Inputs that need special handling instead of direct output.
     *
     * @var array<string, list<string>>
     */
    public const INPUTS = [
        'combinedName' => ['DAA'],
        'givenNames' => ['DCT'],
        'expirationDate' => ['DBA'],
        'dateOfBirth' => ['DBB'],
    ];

    /**
     * @param array<string, string> $fields
     * @param array<string, list<string>> $mapping
     * @return array<string, string>
     */
    public static function map(array $fields, array $mapping = self::FIELDS): array
    {
        $result = [];
        foreach ($mapping as $name => $codes) {
            $result[$name] = '';
            foreach ($codes as $code) {
                if (isset($fields[$code])) {
                    $result[$name] = trim($fields[$code]);
                    break;
                }
            }
        }

        return $result;
    }
}
