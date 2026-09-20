<?php

declare(strict_types=1);

namespace WooCommerceBundle\Controller\Webhook;

use App\Repository\BundleStatusRepository;
use App\Security\Csrf\Attribute\CsrfExempt;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use WooCommerceBundle\Entity\WooCommerceConnection;
use WooCommerceBundle\Entity\WooCommerceOrderImport;
use WooCommerceBundle\Repository\WooCommerceConnectionRepository;
use WooCommerceBundle\Service\WooCommerceOrderImportService;

/**
 * Receives WooCommerce's order.* webhook deliveries (#739).
 *
 * One route per connection, keyed by its slug rather than a single shared endpoint: each store has
 * its own webhook secret, and a slug in the URL is what lets WooCommerceConnection::$slug double as
 * "how do I address this store's endpoint" as well as its SKU-derivation prefix.
 *
 * The route is deliberately public (see config/packages/security.yaml), the same shape as
 * StripeWebhookController: WooCommerce is not a logged-in user and cannot carry a CSRF token.
 * Authenticity comes solely from the X-WC-Webhook-Signature check below, verified against the raw
 * request body before anything parses it — HMAC-SHA256, base64-encoded, exactly as
 * WC_Webhook::generate_signature() computes it (confirmed against WooCommerce's own source, not
 * assumed): base64_encode(hash_hmac('sha256', $rawBody, $secret, true)).
 */
#[Route('/webhook/woocommerce')]
#[CsrfExempt(reason: 'Called by WooCommerce, which cannot know our token. Authenticated instead by verifying X-WC-Webhook-Signature against the connection\'s own webhook secret.')]
final class WooCommerceWebhookController extends AbstractController
{
    public function __construct(
        private readonly BundleStatusRepository $bundleStatusRepo,
        private readonly WooCommerceConnectionRepository $connections,
        private readonly WooCommerceOrderImportService $importer,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/{slug}', name: 'webhook_woocommerce_order', methods: ['POST'])]
    public function __invoke(string $slug, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$this->bundleStatusRepo->isActive('WooCommerceBundle')) {
            throw $this->createNotFoundException('WooCommerce is not active.');
        }

        $connection = $this->connections->findOneBy(['slug' => $slug]);
        if (!$connection instanceof WooCommerceConnection || !$connection->isActive()) {
            // 404 rather than a signature-failure 401: an unknown or switched-off connection's
            // endpoint should read as absent, same as every other bundle-gated screen in this app,
            // and not hint to a prober that the slug is real but merely unauthenticated.
            throw $this->createNotFoundException('No such WooCommerce connection.');
        }

        $rawBody = (string) $request->getContent();
        $signature = (string) $request->headers->get('X-WC-Webhook-Signature', '');
        if (!$this->signatureValid($connection, $rawBody, $signature)) {
            $this->logger->warning('Rejected a WooCommerce webhook with an invalid signature.', ['connection' => $slug]);

            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        // WooCommerce's "Deliver" test button on the webhook admin screen sends a plain
        // `webhook_id=<id>` body, not JSON — acknowledged so setting up the webhook doesn't show
        // as a failed delivery, without being treated as an order.
        if (str_starts_with(ltrim($rawBody), 'webhook_id=')) {
            return new JsonResponse(['ok' => true, 'ignored' => 'ping']);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            $this->logger->warning('Rejected a WooCommerce webhook with an unparseable body.', ['connection' => $slug]);

            return new JsonResponse(['ok' => false], Response::HTTP_BAD_REQUEST);
        }

        $record = $this->importer->import($connection, $payload, $entityManager);

        // Acknowledged either way: a permanent failure (bad data, an unresolvable product) is
        // recorded on the import row for an admin to fix by hand — WooCommerce retrying the exact
        // same payload would fail the exact same way, so a 4xx/5xx here would only cost WooCommerce
        // a webhook-disable threshold for nothing. See WooCommerceOrderImportService's own docblock.
        return new JsonResponse([
            'ok' => true,
            'status' => $record->getStatus(),
            'imported' => $record->getStatus() === WooCommerceOrderImport::STATUS_IMPORTED,
        ]);
    }

    private function signatureValid(WooCommerceConnection $connection, string $rawBody, string $signature): bool
    {
        $secret = $connection->getWebhookSecret();
        if ($secret === '' || $signature === '') {
            return false;
        }

        // wp_specialchars_decode(..., ENT_QUOTES) on the WooCommerce side before hashing: a secret
        // pasted from somewhere that HTML-escaped it (e.g. &amp; for &) must decode the same way to
        // still match. htmlspecialchars_decode with ENT_QUOTES is PHP's own equivalent.
        $decodedSecret = htmlspecialchars_decode($secret, ENT_QUOTES);
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $decodedSecret, true));

        return hash_equals($expected, $signature);
    }
}
