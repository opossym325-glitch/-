<?php

declare(strict_types=1);

namespace App\MarketplaceAccount;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class MarketplaceAccountData
{
    public function __construct(
        #[Assert\NotBlank(message: 'Маркетплейс')]
        public ?string $marketplace,
        #[Assert\NotBlank(message: 'Юридическое лицо')]
        public ?string $legalEntity,
        #[Assert\NotBlank(message: 'Схема работы')]
        public ?string $operationScheme,
        #[Assert\NotBlank(message: 'Идентификатор кабинета маркетплейса')]
        public ?string $marketplaceAccountId,
        #[Assert\NotBlank(message: 'WERKS')]
        public ?string $werks,
    ) {
    }
}
