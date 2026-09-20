<?php

declare(strict_types=1);

namespace App\Contract\Connector;

/**
 * The seam a bundle registers through to appear as a connector type on core's own
 * `/admin/connectors` directory (#741) — "we will be adding many different types of connectors and
 * it needs a common landing spot" (owner, 2026-09-18).
 *
 * Same shape as every other tagged-provider seam in this app (see
 * {@see \App\Contract\Inventory\LotAvailabilityProviderInterface} for the pattern this follows):
 * getSource() names the owning bundle so the App Management Active/Inactive kill-switch applies
 * without the provider checking its own status, and core never holds a compile-time reference to
 * the bundle implementing this — exactly as core knows nothing about InventoryDepthBundle by name.
 *
 * A connector's own credentials, settings screens, and per-connection detail stay entirely owned by
 * its bundle. This interface exists only to answer what the directory row needs: a label, what the
 * connector is for, where "Manage" sends the admin, and a rolled-up view of its connections — never
 * the connections' own credentials or settings.
 *
 * {@see \App\Service\Connector\ConnectorRegistry} collects every active implementation and
 * aggregates them (the "ask every sibling module to contribute rows" shape — see
 * `ProductActivityFeedResolver` — not the first-responder shape some other seams use), since the
 * directory has to show every connected type at once, not stop at the first.
 */
interface ConnectorTypeProviderInterface
{
    /** The owning bundle's source, checked against App\Repository\BundleStatusRepository. */
    public function getSource(): string;

    /** Stable slug identifying this connector type, e.g. 'woocommerce'. Never shown to the admin. */
    public function getType(): string;

    /** Short display name for the directory row, e.g. 'WooCommerce'. */
    public function getLabel(): string;

    /** One sentence: what this connector is for, e.g. 'Pulls in paid orders, syncs stock back out'. */
    public function getDescription(): string;

    /** Route name the directory's "Manage" button links to — owned and defined by the bundle itself. */
    public function getManageRouteName(): string;

    /** @return list<ConnectorConnectionSummary> Every connection currently configured for this type. */
    public function connections(): array;
}
