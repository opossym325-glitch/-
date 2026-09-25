<?php

declare(strict_types=1);

namespace App\MarketplaceAccount;

final readonly class MarketplaceAccountData
{
    public function __construct(
        public ?string $marketplace,
        public ?string $operationScheme,
        public ?string $firmagName,
        public ?string $brand,
        public ?string $marketplaceAccountId,
        public ?string $legalEntityId,
        public ?string $legalEntityName,
        public ?string $werkId,
        /** @var list<string> */
        public array $warehousesWithStock,
    ) {
        // TODO: добавить подтверждённые business constraints после получения request schema 1С.
    }
}
