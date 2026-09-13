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
     *     fullEXP: string,
     *     fullDOB: string
     * }
     */
    public function parse(string $aamvaData): array
    {
        return $this->parseDocument($aamvaData)->normalized();
    }

    /** Parse an AAMVA payload without discarding metadata or unknown elements. */
    public function parseDocument(string $aamvaData): ParsedDocument
    {
        $data = $this->cleanInput(new DataHandler()->decode($aamvaData));

        $directory = [];
        $directoryEnd = 0;
        $warnings = [];
        $data = $this->firstDocument($data, $warnings);
        $metadata = [
            'complianceIndicator' => str_starts_with($data, '@'),
            'fileType' => null,
            'issuerIdentificationNumber' => null,
            'aamvaVersion' => null,
            'standardPublicationYear' => null,
            'jurisdictionVersion' => null,
            'declaredSubfileCount' => null,
        ];

        if (preg_match('/(?:ANSI|AAMVA) */i', $data, $fileTypeMatch, PREG_OFFSET_CAPTURE) === 1) {
            $fileType = strtoupper(trim($fileTypeMatch[0][0]));
            $ansiOffset = (int) $fileTypeMatch[0][1];
            $headerText = substr($data, $ansiOffset);
            if (preg_match('/^(?:ANSI|AAMVA) *(\d{6})(\d{2})(\d{2})?(\d{2})(?=[A-Z]|$)/i', $headerText, $matches) === 1) {
                $metadata = [
                    'complianceIndicator' => $metadata['complianceIndicator'],
                    'fileType' => $fileType,
                    'issuerIdentificationNumber' => $matches[1],
                    'aamvaVersion' => (int) $matches[2],
                    'standardPublicationYear' => AamvaStandard::publicationYear((int) $matches[2]),
                    'jurisdictionVersion' => $matches[3] === '' ? null : (int) $matches[3],
                    'declaredSubfileCount' => (int) $matches[4],
                ];
                $directoryEnd = $ansiOffset + strlen($matches[0]);
                [$directory, $directoryEnd] = $this->readDirectory(
                    $data,
                    $directoryEnd,
                    (int) $matches[4],
                );
                if (count($directory) !== (int) $matches[4]) {
                    $warnings[] = 'The subfile directory is shorter than its declared entry count.';
                }
                if (!AamvaStandard::isRecognized($metadata['aamvaVersion'])) {
                    $warnings[] = sprintf(
                        'AAMVA version %02d is not a recognized standard generation.',
                        $metadata['aamvaVersion'],
                    );
                }
            } else {
                $metadata['fileType'] = $fileType;
                $directoryEnd = $ansiOffset + strlen($fileTypeMatch[0][0]);
                $warnings[] = sprintf('The %s header is incomplete or malformed.', $fileType);
            }
        }

        if ($metadata['fileType'] !== null && !$metadata['complianceIndicator']) {
            $warnings[] = 'The payload has a header but no AAMVA compliance indicator.';
        }

        foreach ($directory as $entry) {
            if ($entry['offset'] < 1 || $entry['offset'] > strlen($data)) {
                $warnings[] = sprintf('Subfile %s has an out-of-range offset.', $entry['type']);
            }
            if ($entry['length'] < 1) {
                $warnings[] = sprintf('Subfile %s has an invalid zero length.', $entry['type']);
            }
        }

        $subfiles = $directory === []
            ? $this->parseLooseSubfiles($data, $directoryEnd)
            : $this->parseDirectorySubfiles($data, $directory, $directoryEnd, $warnings);

        $elements = [];
        foreach ($subfiles as $subfile) {
            foreach ($subfile['elements'] as $designator => $values) {
                $elements[$designator] = array_merge($elements[$designator] ?? [], $values);
            }
        }

        if ($elements === []) {
            throw new InvalidArgumentException('The value does not contain any AAMVA data elements.');
        }

        // Unknown designators are retained for valid documents, but arbitrary
        // uppercase text is not enough to identify headerless input as AAMVA.
        if ($metadata['issuerIdentificationNumber'] === null && !$this->hasRecognizedElement($elements)) {
            throw new InvalidArgumentException('The value has no structured AAMVA header or recognized AAMVA data elements.');
        }

        $fields = array_map(
            static fn (array $values): string => $values[array_key_last($values)],
            $elements,
        );

        return new ParsedDocument(
            $this->normalize($fields),
            $elements,
            $metadata,
            $subfiles,
            $warnings,
        );
    }

    /** @param array<string, list<string>> $elements */
    private function hasRecognizedElement(array $elements): bool
    {
        $designators = array_merge(
            ['DAQ', 'DBJ', 'DBD', 'DBC', 'DCA', 'DCB', 'DCD', 'DCF', 'DCG',
                'DAU', 'DAW', 'DAX', 'DAY', 'DAZ', 'DCU', 'DCE', 'DCI', 'DCJ',
                'DCK', 'DCL', 'DCM', 'DCN', 'DCO', 'DCP', 'DCQ', 'DCR',
                'DBN', 'DBG', 'DBS', 'DDA', 'DDB', 'DDC', 'DDD', 'DDE', 'DDF',
                'DDG', 'DDH', 'DDI', 'DDJ', 'DDK', 'DDL', 'DDM', 'DDN', 'DDO', 'DDP'],
            ...array_values([...FieldMapping::FIELDS, ...FieldMapping::INPUTS]),
        );

        return array_intersect_key($elements, array_fill_keys($designators, true)) !== [];
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

    /** @param list<string> $warnings */
    private function firstDocument(string $data, array &$warnings): string
    {
        $end = strpos($data, "\x04");
        $end = $end === false ? strlen($data) : $end;

        // Scanner buffers can contain multiple scans, even without EOT.
        // A later header starts another document, never another data element.
        if (preg_match('/[\x00-\x20|]\K@[\x00-\x20|]*(?:ANSI|AAMVA) *\d{6}/i', $data, $match, PREG_OFFSET_CAPTURE) === 1) {
            $end = min($end, $match[0][1]);
        }

        if ($end < strlen($data) && preg_match('/[^\x00-\x20]/', substr($data, $end)) === 1) {
            $warnings[] = 'Additional scanner data follows the first document; only the first document was parsed.';
        }

        return substr($data, 0, $end);
    }

    /**
     * @return array{list<array{type: string, offset: int, length: int}>, int}
     */
    private function readDirectory(string $data, int $offset, int $entryCount): array
    {
        $directory = [];

        for ($entry = 0; $entry < $entryCount; $entry++) {
            $designator = substr($data, $offset, 10);
            if (preg_match('/^([A-Z0-9]{2})(\d{4})(\d{4})$/i', $designator, $matches) !== 1) {
                break;
            }

            $directory[] = [
                'type' => strtoupper($matches[1]),
                'offset' => (int) $matches[2],
                'length' => (int) $matches[3],
            ];
            $offset += 10;
        }

        return [$directory, $offset];
    }

    /**
     * @param list<array{type: string, offset: int, length: int}> $directory
     * @return list<array{type: string, offset: int|null, length: int|null, elements: array<string, list<string>>}>
     */
    private function parseDirectorySubfiles(
        string $data,
        array $directory,
        int $directoryEnd,
        array &$warnings,
    ): array
    {
        $starts = [];
        $searchFrom = $directoryEnd;

        foreach ($directory as $index => $entry) {
            $expected = max(0, $entry['offset'] - 1);
            $start = false;
            $unboundedCandidate = false;
            foreach (array_unique([$expected, $entry['offset']]) as $candidate) {
                if ($candidate >= $searchFrom && strcasecmp(substr($data, $candidate, 2), $entry['type']) === 0) {
                    $unboundedCandidate = $unboundedCandidate === false ? $candidate : $unboundedCandidate;
                    if ($candidate === $directoryEnd || preg_match('/[\x00-\x1F|]/', $data[$candidate - 1]) === 1) {
                        $start = $candidate;
                        break;
                    }
                }
            }

            if ($start === false) {
                // Recovery must start at a record boundary, not a matching
                // pair of letters inside an address or another field value.
                $boundary = $searchFrom === $directoryEnd ? '(?:\G|[\x00-\x1F|])' : '[\x00-\x1F|]';
                $pattern = '/' . $boundary . ' *\K' . preg_quote($entry['type'], '/')
                    . '(?=[A-Z]{3}|[\x00-\x20|]|$)/i';
                if (preg_match($pattern, $data, $match, PREG_OFFSET_CAPTURE, $searchFrom) === 1) {
                    $start = $match[0][1];
                }
            }

            // Some subfiles are directly adjacent without a record separator.
            // Prefer a record boundary over offsets landing inside ZWZWA, etc.
            if ($start === false) {
                $start = $unboundedCandidate;
            }

            if ($start === false) {
                $warnings[] = sprintf('Subfile %s could not be located at its declared offset.', $entry['type']);
                continue;
            } elseif ($start !== $expected && $start !== $entry['offset']) {
                $warnings[] = sprintf('Subfile %s was recovered from an inaccurate offset.', $entry['type']);
            }

            $starts[$index] = $start;
            $searchFrom = $start + 2;
        }

        $subfiles = [];
        $located = array_keys($starts);
        foreach ($located as $position => $index) {
            $entry = $directory[$index];
            $start = $starts[$index];
            $nextStart = isset($located[$position + 1]) ? $starts[$located[$position + 1]] : null;
            $declaredEnd = min(strlen($data), $start + $entry['length']);
            $end = $nextStart !== null && $nextStart > $start ? $nextStart : $declaredEnd;

            if ($nextStart !== null && $declaredEnd !== $nextStart) {
                $warnings[] = sprintf('Subfile %s has an inaccurate declared length.', $entry['type']);
            }

            // A number of issuers publish inaccurate lengths. Preserve the
            // final subfile through the end rather than silently dropping data.
            if ($nextStart === null) {
                $end = strlen($data);
            }

            $payload = substr($data, $start, max(0, $end - $start));
            $subfiles[] = [
                'type' => $entry['type'],
                'offset' => $entry['offset'],
                'length' => $entry['length'],
                'elements' => $this->parseFields($payload, $entry['type']),
            ];
        }

        return $subfiles === [] ? $this->parseLooseSubfiles($data, $directoryEnd) : $subfiles;
    }

    /**
     * @return list<array{type: string, offset: int|null, length: int|null, elements: array<string, list<string>>}>
     */
    private function parseLooseSubfiles(string $data, int $headerEnd): array
    {
        $records = $this->records(substr($data, $headerEnd));
        $subfiles = [];
        $currentType = 'DL';

        foreach ($records as $record) {
            $record = trim($record);
            if ($record === '' || str_starts_with($record, '@') || str_starts_with(strtoupper($record), 'ANSI')) {
                continue;
            }

            if (preg_match('/^(USA|CAN)$/i', $record) === 1) {
                continue;
            }

            if (preg_match('/^([A-Z0-9]{2})$/i', $record, $typeMatch) === 1) {
                $currentType = strtoupper($typeMatch[1]);
                $subfiles[$currentType] ??= $this->newLooseSubfile($currentType);
                continue;
            }

            if (preg_match('/^(DL|ID)([A-Z]{3}.*)$/is', $record, $prefixed) === 1) {
                $currentType = strtoupper($prefixed[1]);
                $record = $prefixed[2];
            }

            // Headerless mixed-case input still needs a DL/ID or jurisdiction
            // designator; do not turn ordinary lowercase prose into fields.
            if (preg_match('/^([dDzZ][A-Za-z]{2}|[A-Z]{3})(.*)$/s', $record, $fieldMatch) !== 1) {
                continue;
            }

            $subfiles[$currentType] ??= $this->newLooseSubfile($currentType);
            $subfiles[$currentType]['elements'][strtoupper($fieldMatch[1])][] = trim($fieldMatch[2]);
        }

        return array_values($subfiles);
    }

    /** @return array{type: string, offset: null, length: null, elements: array<string, list<string>>} */
    private function newLooseSubfile(string $type): array
    {
        return ['type' => $type, 'offset' => null, 'length' => null, 'elements' => []];
    }

    /** @return array<string, list<string>> */
    private function parseFields(string $payload, string $subfileType): array
    {
        if (strcasecmp(substr($payload, 0, 2), $subfileType) === 0) {
            $payload = substr($payload, 2);
        }

        $elements = [];
        foreach ($this->records($payload) as $record) {
            $record = trim($record);
            if (preg_match('/^([A-Z]{3})(.*)$/is', $record, $matches) === 1) {
                $elements[strtoupper($matches[1])][] = trim($matches[2]);
            }
        }

        return $elements;
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
     *     dobMM: string, dobDD: string, dobYYYY: string, fullEXP: string, fullDOB: string
     * }
     */
    private function normalize(array $fields): array
    {
        $mapped = FieldMapping::map($fields);
        $inputs = FieldMapping::map($fields, FieldMapping::INPUTS);
        $first = $mapped['first'];
        $last = $mapped['last'];
        $middle = $mapped['mid'];

        if (($first === '' || $last === '' || $middle === '') && $inputs['combinedName'] !== '') {
            [$combinedLast, $combinedFirst, $combinedMiddle] = $this->splitCombinedName($inputs['combinedName']);
            $last = $last !== '' ? $last : $combinedLast;
            $first = $first !== '' ? $first : $combinedFirst;
            $middle = $middle !== '' ? $middle : $combinedMiddle;
        }

        if ($first === '' && $inputs['givenNames'] !== '') {
            $givenNames = preg_split('/\s+/', $inputs['givenNames'], 2) ?: [];
            $first = $givenNames[0] ?? '';
            $middle = $middle !== '' ? $middle : ($givenNames[1] ?? '');
        }

        if (strcasecmp($middle, 'NONE') === 0) {
            $middle = '';
        }

        $expiration = $this->parseDate($inputs['expirationDate']);
        $birthDate = $this->parseDate($inputs['dateOfBirth']);

        return array_replace($mapped, [
            'first' => $first,
            'last' => $last,
            'mid' => $middle,
            'expMM' => $expiration['month'],
            'expDD' => $expiration['day'],
            'expYYYY' => $expiration['year'],
            'dobMM' => $birthDate['month'],
            'dobDD' => $birthDate['day'],
            'dobYYYY' => $birthDate['year'],
            'fullEXP' => $expiration['year'] . $expiration['day'] . $expiration['month'],
            'fullDOB' => $birthDate['year'] . $birthDate['day'] . $birthDate['month'],
        ]);
    }

    /** @return array{string, string, string} */
    private function splitCombinedName(string $name): array
    {
        $parts = array_map('trim', explode(',', $name));

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
