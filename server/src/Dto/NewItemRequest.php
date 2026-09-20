<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The entire accepted POST body.
 *
 * There is nothing here but the product. No client id, no nickname, no
 * device fingerprint, no timestamp from the caller — any of those would
 * turn a contribution into a record of who was holding the phone. Anything
 * extra the client sends is ignored rather than stored.
 *
 * `codes` is normalised in the constructor rather than left to the
 * serializer. A promoted `array $codes` says nothing about what it holds,
 * and the `@var SubmittedCode[]` hint is only read when
 * phpdocumentor/reflection-docblock happens to be installed. Without it the
 * elements arrive as plain arrays and the first `->value` raises
 * "Attempt to read property on array". Building them here makes the shape a
 * property of this class instead of a property of the dependency tree.
 */
final class NewItemRequest
{
    /** @var SubmittedCode[] */
    #[Assert\Count(min: 1, max: 4, minMessage: 'At least one code is required')]
    #[Assert\Valid]
    public readonly array $codes;

    /** @param array<mixed> $codes raw rows, or SubmittedCode instances */
    public function __construct(
        #[Assert\NotBlank(message: 'A product name is required')]
        #[Assert\Length(min: 1, max: 120)]
        public readonly string $name = '',
        array $codes = [],
    ) {
        $this->codes = CodeShape::listFrom($codes);
    }
}
