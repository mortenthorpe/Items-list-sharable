<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Canonical form for a product code, mirroring what the browser client does.
 *
 * The same product reaches us spelled several ways: a UPC-A is an EAN-13
 * without its leading zero, and a QR on packaging usually carries a GS1
 * Digital Link URL wrapped around the same number rather than bare digits.
 * Numeric codes canonicalise to a zero-padded GTIN-14; anything else is
 * kept as-is apart from surrounding whitespace and a trailing slash.
 */
final class CodeNormaliser
{
    public function canonical(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            return '';
        }

        $digits = $this->digitsWithin($trimmed);

        if ($digits !== null) {
            return str_pad($digits, 14, '0', STR_PAD_LEFT);
        }

        // Case is preserved deliberately: a QR payload is often a URL, and
        // URL paths are case-sensitive. Only a trailing slash is noise.
        return rtrim($trimmed, '/');
    }

    /**
     * The GTIN inside a code, whether it arrived bare or inside a digital
     * link such as https://id.example.org/01/05701120030018?lot=7
     */
    public function digitsWithin(string $trimmed): ?string
    {
        if (preg_match('#^\d{8,14}$#', $trimmed) === 1) {
            return ltrim($trimmed, '0') === '' ? '0' : ltrim($trimmed, '0');
        }

        if (preg_match('#(?:^|/)01/(\d{8,14})(?:[/?\#]|$)#', $trimmed, $matches) === 1) {
            $gtin = ltrim($matches[1], '0');

            return $gtin === '' ? '0' : $gtin;
        }

        return null;
    }

    /** @return string[] canonical keys for every supplied code */
    public function canonicalAll(array $values): array
    {
        $keys = [];

        for ($i = 0, $count = count($values); $i < $count; $i++) {
            $key = $this->canonical((string) $values[$i]);
            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
