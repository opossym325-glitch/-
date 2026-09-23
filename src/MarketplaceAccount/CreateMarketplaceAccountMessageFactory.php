<?php

declare(strict_types=1);

namespace App\MarketplaceAccount;

final class CreateMarketplaceAccountMessageFactory
{
    /** @return array<string, string> */
    public function create(MarketplaceAccountData $data, string $correlationId): array
    {
        // TODO: подтвердить с 1С точную request schema и имя поля correlationId.
        return [
            'correlationId' => $correlationId,
            'marketplace' => (string) $data->marketplace,
            'legalEntity' => (string) $data->legalEntity,
            'operationScheme' => (string) $data->operationScheme,
            'marketplaceAccountId' => (string) $data->marketplaceAccountId,
            'werks' => (string) $data->werks,
        ];
    }
}
