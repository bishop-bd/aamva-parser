<?php

declare(strict_types=1);

namespace AamvaParser;

use JsonSerializable;

/**
 * A lossless representation of an AAMVA DL/ID payload.
 *
 * The legacy normalized array remains available through normalized(), while
 * elements() preserves unknown and repeated data elements for every issuer.
 */
final readonly class ParsedDocument implements JsonSerializable
{
    /**
     * @param array<string, string> $normalized
     * @param array<string, list<string>> $elements
     * @param array{
     *     complianceIndicator: bool,
     *     fileType: string|null,
     *     issuerIdentificationNumber: string|null,
     *     aamvaVersion: int|null,
     *     standardPublicationYear: int|null,
     *     jurisdictionVersion: int|null,
     *     declaredSubfileCount: int|null
     * } $metadata
     * @param list<array{
     *     type: string,
     *     offset: int|null,
     *     length: int|null,
     *     elements: array<string, list<string>>
     * }> $subfiles
     * @param list<string> $warnings
     */
    public function __construct(
        private array $normalized,
        private array $elements,
        private array $metadata,
        private array $subfiles,
        private array $warnings = [],
    ) {
    }

    /** @return array<string, string> */
    public function normalized(): array
    {
        return $this->normalized;
    }

    /** @return array<string, list<string>> */
    public function elements(): array
    {
        return $this->elements;
    }

    /** @return list<string> */
    public function values(string $designator): array
    {
        return $this->elements[strtoupper($designator)] ?? [];
    }

    public function value(string $designator): ?string
    {
        $values = $this->values($designator);

        return $values === [] ? null : $values[array_key_last($values)];
    }

    /**
     * @return array{
     *     complianceIndicator: bool,
     *     fileType: string|null,
     *     issuerIdentificationNumber: string|null,
     *     aamvaVersion: int|null,
     *     standardPublicationYear: int|null,
     *     jurisdictionVersion: int|null,
     *     declaredSubfileCount: int|null
     * }
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return list<array{
     *     type: string,
     *     offset: int|null,
     *     length: int|null,
     *     elements: array<string, list<string>>
     * }>
     */
    public function subfiles(): array
    {
        return $this->subfiles;
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'normalized' => $this->normalized,
            'elements' => $this->elements,
            'metadata' => $this->metadata,
            'subfiles' => $this->subfiles,
            'warnings' => $this->warnings,
        ];
    }
}

