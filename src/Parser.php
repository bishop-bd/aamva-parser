<?php

declare(strict_types=1);

namespace AamvaParser;

use InvalidArgumentException;

class Parser
{
    /**
     * Parse an AAMVA DL/ID barcode payload.
     *
     * @return array{
     *     first: string,
     *     last: string,
     *     mid: string,
     *     address: string,
     *     address2: string,
     *     city: string,
     *     state: string,
     *     zip: string,
     *     expMM: string,
     *     expDD: string,
     *     expYYYY: string,
     *     dobMM: string,
     *     dobDD: string,
     *     dobYYYY: string,
     *     fullEXP: string
     * }
     */
    public function parse(string $aamvaData): array
    {
        $data = $this->cleanInput(new DataHandler()->decode($aamvaData));

        $directory = [];
        $directoryEnd = 0;

        if (preg_match('/ANSI ?/', $data, $fileTypeMatch, PREG_OFFSET_CAPTURE) === 1) {
            $ansiOffset = (int) $fileTypeMatch[0][1];
            $headerText = substr($data, $ansiOffset);
            if (preg_match('/^ANSI ?(\d{6})(\d{2})(\d{2})(\d{2})/', $headerText, $matches) === 1) {
                $directoryEnd = $ansiOffset + strlen($matches[0]);
                [$directory, $directoryEnd] = $this->readDirectory(
                    $data,
                    $directoryEnd,
                    (int) $matches[4],
                );
            }
        } elseif (preg_match('/AAMVA/', $data, $fileTypeMatch, PREG_OFFSET_CAPTURE) === 1) {
            // Legacy payloads used AAMVA instead of ANSI as the file type.
            $directoryEnd = (int) $fileTypeMatch[0][1] + strlen($fileTypeMatch[0][0]);
        }

        $subfiles = $directory === []
            ? $this->parseLooseSubfiles($data, $directoryEnd)
            : $this->parseDirectorySubfiles($data, $directory, $directoryEnd);

        $fields = [];
        foreach ($subfiles as $subfile) {
            $fields = array_replace($fields, $subfile['fields']);
        }

        if ($fields === []) {
            throw new InvalidArgumentException('The value does not contain any AAMVA data elements.');
        }

        return $this->normalize($fields);
    }

    private function cleanInput(string $data): string
    {
        $data = preg_replace('/^\xEF\xBB\xBF/', '', $data) ?? $data;
        $data = trim($data, " \t\n\r\0\x0B");

        if ($data === '') {
            throw new InvalidArgumentException('AAMVA data cannot be empty.');
        }

        $complianceIndicator = strpos($data, '@');

        return $complianceIndicator === false ? $data : substr($data, $complianceIndicator);
    }

    /**
     * @return array{list<array{type: string, offset: int, length: int}>, int}
     */
    private function readDirectory(string $data, int $offset, int $entryCount): array
    {
        $directory = [];

        for ($entry = 0; $entry < $entryCount; $entry++) {
            $designator = substr($data, $offset, 10);
            if (preg_match('/^([A-Z0-9]{2})(\d{4})(\d{4})$/', $designator, $matches) !== 1) {
                break;
            }

            $directory[] = [
                'type' => $matches[1],
                'offset' => (int) $matches[2],
                'length' => (int) $matches[3],
            ];
            $offset += 10;
        }

        return [$directory, $offset];
    }

    /**
     * @param list<array{type: string, offset: int, length: int}> $directory
     * @return list<array{type: string, offset: int|null, length: int|null, fields: array<string, string>}>
     */
    private function parseDirectorySubfiles(string $data, array $directory, int $directoryEnd): array
    {
        $starts = [];
        $searchFrom = $directoryEnd;

        foreach ($directory as $index => $entry) {
            $expected = max(0, $entry['offset'] - 1);
            $start = substr($data, $expected, 2) === $entry['type']
                ? $expected
                : strpos($data, $entry['type'], $searchFrom);

            if ($start === false) {
                $start = $expected < strlen($data) ? $expected : $searchFrom;
            }

            $starts[$index] = $start;
            $searchFrom = $start + 2;
        }

        $subfiles = [];
        foreach ($directory as $index => $entry) {
            $start = $starts[$index];
            $nextStart = $starts[$index + 1] ?? null;
            $declaredEnd = min(strlen($data), $start + $entry['length']);
            $end = $nextStart !== null && $nextStart > $start ? $nextStart : $declaredEnd;

            // A number of issuers publish inaccurate lengths. Preserve the
            // final subfile through the end rather than silently dropping data.
            if ($index === array_key_last($directory)) {
                $end = strlen($data);
            }

            $payload = substr($data, $start, max(0, $end - $start));
            $subfiles[] = [
                'type' => $entry['type'],
                'offset' => $entry['offset'],
                'length' => $entry['length'],
                'fields' => $this->parseFields($payload, $entry['type']),
            ];
        }

        return $subfiles;
    }

    /**
     * @return list<array{type: string, offset: int|null, length: int|null, fields: array<string, string>}>
     */
    private function parseLooseSubfiles(string $data, int $headerEnd): array
    {
        $records = $this->records(substr($data, $headerEnd));
        $subfiles = [];
        $currentType = 'DL';

        foreach ($records as $record) {
            $record = trim($record);
            if ($record === '' || str_starts_with($record, '@') || str_starts_with($record, 'ANSI')) {
                continue;
            }

            if (preg_match('/^(USA|CAN)$/', $record) === 1) {
                continue;
            }

            if (preg_match('/^([A-Z0-9]{2})$/', $record, $typeMatch) === 1) {
                $currentType = $typeMatch[1];
                $subfiles[$currentType] ??= $this->newLooseSubfile($currentType);
                continue;
            }

            if (preg_match('/^.*?(DL|ID)([A-Z]{3}.*)$/s', $record, $prefixed) === 1) {
                $currentType = $prefixed[1];
                $record = $prefixed[2];
            }

            if (preg_match('/^([A-Z]{3})(.*)$/s', $record, $fieldMatch) !== 1) {
                continue;
            }

            $subfiles[$currentType] ??= $this->newLooseSubfile($currentType);
            $subfiles[$currentType]['fields'][$fieldMatch[1]] = trim($fieldMatch[2]);
        }

        return array_values($subfiles);
    }

    /** @return array{type: string, offset: null, length: null, fields: array<string, string>} */
    private function newLooseSubfile(string $type): array
    {
        return ['type' => $type, 'offset' => null, 'length' => null, 'fields' => []];
    }

    /** @return array<string, string> */
    private function parseFields(string $payload, string $subfileType): array
    {
        if (str_starts_with($payload, $subfileType)) {
            $payload = substr($payload, 2);
        }

        $fields = [];
        foreach ($this->records($payload) as $record) {
            $record = trim($record);
            if (preg_match('/^([A-Z]{3})(.*)$/s', $record, $matches) === 1) {
                $fields[$matches[1]] = trim($matches[2]);
            }
        }

        return $fields;
    }

    /** @return list<string> */
    private function records(string $data): array
    {
        $pattern = str_contains($data, '|') ? '/[\x00-\x1F|]+/' : '/[\x00-\x1F]+/';

        return preg_split($pattern, $data, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * @param array<string, string> $fields
     * @return array{
     *     first: string, last: string, mid: string, address: string,
     *     address2: string, city: string, state: string, zip: string,
     *     expMM: string, expDD: string, expYYYY: string,
     *     dobMM: string, dobDD: string, dobYYYY: string, fullEXP: string
     * }
     */
    private function normalize(array $fields): array
    {
        $first = trim($fields['DAC'] ?? '');
        $last = trim($fields['DCS'] ?? $fields['DAB'] ?? '');
        $middle = trim($fields['DAD'] ?? '');

        if (($first === '' || $last === '') && isset($fields['DAA'])) {
            [$combinedLast, $combinedFirst, $combinedMiddle] = $this->splitCombinedName($fields['DAA']);
            $last = $last !== '' ? $last : $combinedLast;
            $first = $first !== '' ? $first : $combinedFirst;
            $middle = $middle !== '' ? $middle : $combinedMiddle;
        }

        if ($first === '' && isset($fields['DCT'])) {
            $givenNames = preg_split('/\s+/', trim($fields['DCT']), 2) ?: [];
            $first = $givenNames[0] ?? '';
            $middle = $middle !== '' ? $middle : ($givenNames[1] ?? '');
        }

        if (strcasecmp($middle, 'NONE') === 0) {
            $middle = '';
        }

        $expiration = $this->parseDate($fields['DBA'] ?? '');
        $birthDate = $this->parseDate($fields['DBB'] ?? '');

        return [
            'first' => $first,
            'last' => $last,
            'mid' => $middle,
            'address' => trim($fields['DAG'] ?? ''),
            'address2' => trim($fields['DAH'] ?? ''),
            'city' => trim($fields['DAI'] ?? ''),
            'state' => trim($fields['DAJ'] ?? ''),
            'zip' => trim($fields['DAK'] ?? ''),
            'expMM' => $expiration['month'],
            'expDD' => $expiration['day'],
            'expYYYY' => $expiration['year'],
            'dobMM' => $birthDate['month'],
            'dobDD' => $birthDate['day'],
            'dobYYYY' => $birthDate['year'],
            'fullEXP' => $expiration['year'] . $expiration['day'] . $expiration['month'],
        ];
    }

    /** @return array{string, string, string} */
    private function splitCombinedName(string $name): array
    {
        $parts = array_map('trim', explode(',', $name, 3));

        return [
            $parts[0] ?? '',
            $parts[1] ?? '',
            $parts[2] ?? '',
        ];
    }

    /** @return array{month: string, day: string, year: string} */
    private function parseDate(string $date): array
    {
        $digits = preg_replace('/\D/', '', $date) ?? '';
        if (strlen($digits) !== 8) {
            return ['month' => '', 'day' => '', 'year' => ''];
        }

        $leadingYear = (int) substr($digits, 0, 4);
        if ($leadingYear >= 1900 && $leadingYear <= 2199) {
            $year = substr($digits, 0, 4);
            $month = substr($digits, 4, 2);
            $day = substr($digits, 6, 2);
        } else {
            $month = substr($digits, 0, 2);
            $day = substr($digits, 2, 2);
            $year = substr($digits, 4, 4);
        }

        if (!checkdate((int) $month, (int) $day, (int) $year)) {
            return ['month' => '', 'day' => '', 'year' => ''];
        }

        return ['month' => $month, 'day' => $day, 'year' => $year];
    }
}