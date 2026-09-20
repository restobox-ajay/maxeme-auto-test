<?php

declare(strict_types=1);

namespace CartHoldBundle\Controller\Admin;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\Validation\Constraint\ValidCartHoldDuration;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

#[Route('/admin/bundles/cart-hold')]
final class CartHoldConfigController extends AbstractController
{
    private const SETTING_KEY = 'cart_hold_duration_seconds';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $appSettings,
    ) {
    }

    #[Route('', name: 'admin_bundle_cart_hold_config', methods: ['GET', 'POST'])]
    public function config(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $duration = $request->request->getInt('duration_seconds', 300);
            $violations = Validation::createValidator()->validate($duration, new ValidCartHoldDuration());
            if (count($violations) > 0) {
                $this->addFlash('error', (string) $violations[0]->getMessage());
                return $this->redirectToRoute('admin_bundle_cart_hold_config');
            }

            $this->setSetting((string) $duration);
            $this->em->flush();
            $this->appSettings->clearCache();
            $this->addFlash('success', 'Cart hold duration updated.');
            return $this->redirectToRoute('admin_bundle_cart_hold_config');
        }

        return $this->render('@CartHold/config.html.twig', [
            'durationSeconds' => (int) $this->appSettings->get(self::SETTING_KEY, '300'),
        ]);
    }

    private function setSetting(string $value): void
    {
        $setting = $this->em->getRepository(AppSetting::class)->findOneBy(['settingKey' => self::SETTING_KEY]);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey(self::SETTING_KEY)->setCategory('CartHoldBundle');
            $this->em->persist($setting);
        }

        $setting
            ->setName('Cart Hold Duration (seconds)')
            ->setSettingValue($value)
            ->touch();
    }
}
