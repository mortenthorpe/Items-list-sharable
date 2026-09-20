<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The entire accepted body for publishing a list: item numbers, in order.
 *
 * Nothing identifies the sender, and there is no field in which it could.
 *
 * Numbers are cast in the constructor. A client that sends `["1","4"]`
 * rather than `[1,4]` is not wrong in any way the person would understand,
 * and a type-strict 422 would be a puzzling way to tell them so.
 */
final class NewListRequest
{
    /** @var int[] */
    #[Assert\Count(min: 1, max: 500, minMessage: 'Send at least one item')]
    #[Assert\All([
        new Assert\Type('integer'),
        new Assert\Positive(),
    ])]
    public readonly array $items;

    /**
     * An idempotency key the client computes from its own selection and a
     * secret it never sends. Optional: without one the list is simply
     * always created fresh.
     */
    #[Assert\Regex(pattern: '/^[0-9a-f]{64}$/', message: 'fingerprint must be 64 hex characters')]
    public readonly ?string $fingerprint;

    /** @param array<mixed> $items */
    public function __construct(array $items = [], ?string $fingerprint = null)
    {
        $this->fingerprint = is_string($fingerprint) && $fingerprint !== ''
            ? strtolower(trim($fingerprint))
            : null;

        $rows = array_values($items);
        $numbers = [];

        for ($i = 0, $count = count($rows); $i < $count; $i++) {
            if (is_int($rows[$i])) {
                $numbers[] = $rows[$i];
                continue;
            }

            if (is_string($rows[$i]) && preg_match('#^\d+$#', trim($rows[$i])) === 1) {
                $numbers[] = (int) trim($rows[$i]);
            }
        }

        $this->items = $numbers;
    }
}
