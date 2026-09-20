<?php

declare(strict_types=1);

namespace WooCommerceBundle\Connector;

use App\Contract\Connector\ConnectorConnectionSummary;
use App\Contract\Connector\ConnectorStatusTone;
use App\Contract\Connector\ConnectorTypeProviderInterface;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;

/**
 * WooCommerce's registration into the core connector directory (#741) — the first real implementer
 * of ConnectorTypeProviderInterface. Everything WooCommerce-specific (credentials, tier mapping,
 * the webhook receiver) stays entirely in this bundle; core only ever sees what this class reports.
 */
final class WooCommerceConnectorTypeProvider implements ConnectorTypeProviderInterface
{
    public function __construct(
        private readonly WooCommerceConnectionRepository $connections,
    ) {
    }

    public function getSource(): string
    {
        return 'WooCommerceBundle';
    }

    public function getType(): string
    {
        return 'woocommerce';
    }

    public function getLabel(): string
    {
        return 'WooCommerce';
    }

    public function getDescription(): string
    {
        return 'Pulls in paid orders, syncs stock back out';
    }

    public function getManageRouteName(): string
    {
        return 'admin_bundle_woocommerce_connections';
    }

    /** @return list<ConnectorConnectionSummary> */
    public function connections(): array
    {
        $summaries = [];

        foreach ($this->connections->findAllOrderedByName() as $connection) {
            if (!$connection->isActive()) {
                $summaries[] = new ConnectorConnectionSummary(
                    name: $connection->getName(),
                    statusTone: ConnectorStatusTone::Warn,
                    statusLabel: 'Off',
                    lastActivityAt: $connection->getLastOrderAt(),
                );

                continue;
            }

            $hasCredentials = $connection->getConsumerKey() !== ''
                && $connection->getConsumerSecret() !== ''
                && $connection->getWebhookSecret() !== '';

            $summaries[] = new ConnectorConnectionSummary(
                name: $connection->getName(),
                statusTone: $hasCredentials ? ConnectorStatusTone::Ok : ConnectorStatusTone::Warn,
                statusLabel: $hasCredentials ? 'Connected' : 'Credentials incomplete',
                lastActivityAt: $connection->getLastOrderAt(),
            );
        }

        return $summaries;
    }
}
