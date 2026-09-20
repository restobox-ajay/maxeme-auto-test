<?php

declare(strict_types=1);

namespace PaymentStripeBundle\Controller;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use Doctrine\ORM\EntityManagerInterface;
use PaymentStripeBundle\Service\StripeConfigProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin/bundles/payments')]
final class StripePaymentConfigController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $appSettings,
    ) {}

    #[Route('/stripe', name: 'admin_bundle_payment_stripe', methods: ['GET', 'POST'])]
    public function config(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $mode = $request->request->get('mode') === 'live' ? 'live' : 'test';
            $this->upsertSetting(StripeConfigProvider::KEY_MODE, $mode, 'Stripe Mode');
            $this->upsertSetting(StripeConfigProvider::KEY_PUBLISHABLE_TEST, trim((string) $request->request->get('publishable_key_test', '')), 'Stripe Publishable Key (Test)');
            $this->upsertSetting(StripeConfigProvider::KEY_PUBLISHABLE_LIVE, trim((string) $request->request->get('publishable_key_live', '')), 'Stripe Publishable Key (Live)');

            // Secret fields are masked on render and never round-tripped — a blank submit
            // means "leave the current key alone", not "clear it".
            $secretTest = trim((string) $request->request->get('secret_key_test', ''));
            if ($secretTest !== '') {
                $this->upsertSetting(StripeConfigProvider::KEY_SECRET_TEST, $secretTest, 'Stripe Secret Key (Test)');
            }
            $secretLive = trim((string) $request->request->get('secret_key_live', ''));
            if ($secretLive !== '') {
                $this->upsertSetting(StripeConfigProvider::KEY_SECRET_LIVE, $secretLive, 'Stripe Secret Key (Live)');
            }

            // Endpoint signing secrets (whsec_...), same masked/blank-keeps-current handling.
            // Without one, StripeWebhookController refuses every event rather than trusting an
            // unsigned payload, so an order paid after the customer closes the tab stays unpaid.
            $webhookTest = trim((string) $request->request->get('webhook_secret_test', ''));
            if ($webhookTest !== '') {
                $this->upsertSetting(StripeConfigProvider::KEY_WEBHOOK_SECRET_TEST, $webhookTest, 'Stripe Webhook Signing Secret (Test)');
            }
            $webhookLive = trim((string) $request->request->get('webhook_secret_live', ''));
            if ($webhookLive !== '') {
                $this->upsertSetting(StripeConfigProvider::KEY_WEBHOOK_SECRET_LIVE, $webhookLive, 'Stripe Webhook Signing Secret (Live)');
            }

            $this->appSettings->clearCache();
            $this->addFlash('success', 'Stripe configuration updated.');

            return $this->redirectToRoute('admin_bundle_payment_stripe');
        }

        return $this->render('@PaymentStripe/config.html.twig', [
            'mode' => (string) ($this->appSettings->get(StripeConfigProvider::KEY_MODE, 'test') ?? 'test'),
            'publishableKeyTest' => (string) ($this->appSettings->get(StripeConfigProvider::KEY_PUBLISHABLE_TEST, '') ?? ''),
            'publishableKeyLive' => (string) ($this->appSettings->get(StripeConfigProvider::KEY_PUBLISHABLE_LIVE, '') ?? ''),
            'secretKeyTestMasked' => $this->maskSecret((string) ($this->appSettings->get(StripeConfigProvider::KEY_SECRET_TEST, '') ?? '')),
            'secretKeyLiveMasked' => $this->maskSecret((string) ($this->appSettings->get(StripeConfigProvider::KEY_SECRET_LIVE, '') ?? '')),
            'webhookSecretTestMasked' => $this->maskSecret((string) ($this->appSettings->get(StripeConfigProvider::KEY_WEBHOOK_SECRET_TEST, '') ?? '')),
            'webhookSecretLiveMasked' => $this->maskSecret((string) ($this->appSettings->get(StripeConfigProvider::KEY_WEBHOOK_SECRET_LIVE, '') ?? '')),
            'webhookUrl' => $this->generateUrl('payment_stripe_webhook', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    private function upsertSetting(string $key, string $value, string $name): void
    {
        $setting = $this->em->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if ($setting === null) {
            $setting = (new AppSetting())->setSettingKey($key)->setName($name);
            $this->em->persist($setting);
        }

        $setting->setSettingValue($value !== '' ? $value : null)->touch();
        $this->em->flush();
    }

    private function maskSecret(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strlen($value) <= 8) {
            return str_repeat('•', 4);
        }

        return substr($value, 0, 7) . '••••' . substr($value, -4);
    }
}
