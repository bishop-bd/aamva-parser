<?php

declare(strict_types=1);

namespace AamvaParser;

use InvalidArgumentException;

final class DataHandler
{
    /**
     * Convert a hexadecimal byte string such as "40 0A 1E 0D" to binary text.
     */
    public function fromByteString(string $byteString): string
    {
        $byteString = trim($byteString);
        if (!$this->isByteString($byteString)) {
            throw new InvalidArgumentException('The byte string must contain two-digit hexadecimal bytes.');
        }

        $bytes = preg_split('/\s*,\s*|\s+/', $byteString, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $decoded = '';

        foreach ($bytes as $byte) {
            $decoded .= chr((int) hexdec(preg_replace('/^0x/i', '', $byte) ?? $byte));
        }

        return $decoded;
    }

    /** Decode standard or unpadded Base64 using strict validation. */
    public function base64Decode(string $base64String): string
    {
        $base64String = preg_replace('/\s+/', '', $base64String) ?? '';
        if ($base64String === '' || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $base64String) !== 1) {
            throw new InvalidArgumentException('The value is not valid Base64 data.');
        }

        $remainder = strlen($base64String) % 4;
        if ($remainder === 1) {
            throw new InvalidArgumentException('The value is not valid Base64 data.');
        }

        if ($remainder > 0) {
            $base64String .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($base64String, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('The value is not valid Base64 data.');
        }

        return $decoded;
    }

    /**
     * Decode scanner transport representations while leaving raw AAMVA data
     * unchanged. Multiple layers (for example, hex containing Base64) work too.
     */
    public function decode(string $data): string
    {
        for ($layer = 0; $layer < 3; $layer++) {
            $candidate = trim($data);

            if ($this->isByteString($candidate)) {
                $data = $this->fromByteString($candidate);
                continue;
            }

            if (!$this->couldBeBase64($candidate)) {
                break;
            }

            try {
                $decoded = $this->base64Decode($candidate);
            } catch (InvalidArgumentException) {
                break;
            }

            if (!$this->looksLikeAamva($decoded) && !$this->isByteString(trim($decoded))) {
                break;
            }

            $data = $decoded;
        }

        return $data;
    }

    private function isByteString(string $data): bool
    {
        return preg_match(
            '/^(?:0x)?[0-9A-F]{2}(?:(?:\s+|\s*,\s*)(?:0x)?[0-9A-F]{2})+$/i',
            $data,
        ) === 1;
    }

    private function couldBeBase64(string $data): bool
    {
        $data = preg_replace('/\s+/', '', $data) ?? '';

        return strlen($data) >= 8
            && strlen($data) % 4 !== 1
            && preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $data) === 1;
    }

    private function looksLikeAamva(string $data): bool
    {
        return preg_match('/@[\x00-\x20|]{0,4}(?:ANSI|AAMVA)/i', $data) === 1
            || preg_match(
                '/(?:^|[\x00-\x20|])(?:DL|ID)?(?:DAA|DAB|DCT|DCS|DAC|DAD|DAG|DAH|DAI|DAJ|DAK|DAQ|DBJ|DBA|DBB)[^\x00-\x1F|]*/i',
                $data,
            ) === 1;
    }

}
