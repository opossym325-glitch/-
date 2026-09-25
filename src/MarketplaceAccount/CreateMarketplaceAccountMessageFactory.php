<?php

declare(strict_types=1);

namespace App\MarketplaceAccount;

final class CreateMarketplaceAccountMessageFactory
{
    /** @return array<string, string|list<string>|null> */
    public function create(MarketplaceAccountData $data, string $correlationId): array
    {
        // DRAFT/UNCONFIRMED: payload нужен только для проверки Kafka pipeline и не является контрактом 1С.
        // TODO: заменить только эту boundary-фабрику после получения request schema и правила correlation от 1С.
        return [
            'correlationId' => $correlationId,
            'marketplace' => $data->marketplace,
            'operationScheme' => $data->operationScheme,
            'firmagName' => $data->firmagName,
            'brand' => $data->brand,
            'marketplaceAccountId' => $data->marketplaceAccountId,
            'legalEntityId' => $data->legalEntityId,
            'legalEntityName' => $data->legalEntityName,
            'werkId' => $data->werkId,
            'warehousesWithStock' => $data->warehousesWithStock,
        ];
    }
}
