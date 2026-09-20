<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** A code observed on a product the catalogue already knows. */
final class AdditionalCode
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Choice(choices: ['barcode', 'qr'], message: 'kind must be "barcode" or "qr"')]
        public readonly string $kind = 'barcode',

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $value = '',
    ) {
    }
}
