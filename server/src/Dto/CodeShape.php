<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Turns whatever arrived in a JSON body into code objects.
 *
 * Kept apart from the DTOs so both the submission and the linking payloads
 * read the same rules, and so the rules can be tested without a serializer.
 */
final class CodeShape
{
    /**
     * @param  array<mixed> $raw
     * @return SubmittedCode[]
     */
    public static function listFrom(array $raw): array
    {
        $rows = array_values($raw);
        $codes = [];

        for ($i = 0, $count = count($rows); $i < $count; $i++) {
            $code = self::one($rows[$i]);

            if ($code !== null) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    public static function one(mixed $row): ?SubmittedCode
    {
        if ($row instanceof SubmittedCode) {
            return $row;
        }

        // A bare string is a barcode if it is all digits, a QR payload if not.
        if (is_string($row)) {
            $value = trim($row);

            return $value === ''
                ? null
                : new SubmittedCode(preg_match('#^\d+$#', $value) === 1 ? 'barcode' : 'qr', $value);
        }

        if (!is_array($row)) {
            return null;
        }

        $value = $row['value'] ?? $row['code'] ?? null;

        if (!is_scalar($value)) {
            return null;
        }

        $kind = $row['kind'] ?? $row['type'] ?? $row['symbology'] ?? 'barcode';
        $kind = is_scalar($kind) ? strtolower((string) $kind) : 'barcode';

        return new SubmittedCode(
            str_contains($kind, 'qr') ? 'qr' : 'barcode',
            trim((string) $value),
        );
    }
}
