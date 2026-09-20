<?php

declare(strict_types=1);

namespace NewsBundle\Controller\Admin;

use App\Entity\AppSetting;
use App\Service\AppSettings;
use App\Validation\Constraint\ValidNewsPostRequest;
use Doctrine\ORM\EntityManagerInterface;
use NewsBundle\Entity\NewsPost;
use NewsBundle\Repository\NewsPostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/admin/bundles/news')]
final class NewsPostController extends AbstractController
{
    public const SETTING_SHOW_ON_LOGIN = 'news_show_on_login';
    public const SETTING_SHOW_ON_CATALOG = 'news_show_on_catalog';

    public function __construct(
        private readonly NewsPostRepository $newsPostRepo,
        private readonly EntityManagerInterface $em,
        private readonly AppSettings $appSettings,
    ) {}

    private const SESSION_FORM_STATE_KEY = 'news_post_form_state_';

    #[Route('', name: 'admin_bundle_news_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@News/admin/index.html.twig', [
            'posts' => $this->newsPostRepo->findAllOrdered(),
            'showOnLogin' => $this->appSettings->get(self::SETTING_SHOW_ON_LOGIN) === '1',
            'showOnCatalog' => $this->appSettings->get(self::SETTING_SHOW_ON_CATALOG) === '1',
        ]);
    }

    #[Route('/settings', name: 'admin_bundle_news_settings', methods: ['POST'])]
    public function settings(Request $request): Response
    {
        $this->upsertSetting(
            self::SETTING_SHOW_ON_LOGIN,
            'Show News Scroller on Login Page',
            $request->request->getBoolean('show_on_login') ? '1' : '0',
        );
        $this->upsertSetting(
            self::SETTING_SHOW_ON_CATALOG,
            'Show News Scroller on Catalog Page',
            $request->request->getBoolean('show_on_catalog') ? '1' : '0',
        );

        $this->em->flush();
        $this->appSettings->clearCache();

        $this->addFlash('success', 'Display settings updated.');

        return $this->redirectToRoute('admin_bundle_news_index');
    }

    #[Route('/new', name: 'admin_bundle_news_new', methods: ['GET', 'POST'])]
    public function new(Request $request, ValidatorInterface $validator): Response
    {
        $session = $request->getSession();
        $sessionKey = self::SESSION_FORM_STATE_KEY . 'new';

        if ($request->isMethod('POST')) {
            [$post, $errors] = $this->buildFromRequest($request, new NewsPost(), $validator);
            if (empty($errors)) {
                $this->em->persist($post);
                $this->em->flush();
                $this->addFlash('success', 'News post created.');

                return $this->redirectToRoute('admin_bundle_news_index');
            }

            // Redirect instead of rendering the error directly (Post/Redirect/Get) — otherwise
            // reloading the page resubmits the same invalid POST and the error never goes away
            // until the user navigates elsewhere and back.
            $session->set($sessionKey, ['errors' => $errors, 'data' => $request->request->all()]);

            return $this->redirectToRoute('admin_bundle_news_new');
        }

        $state = $session->remove($sessionKey) ?? [];

        return $this->render('@News/admin/form.html.twig', [
            'mode' => 'Create',
            'post' => null,
            'errors' => $state['errors'] ?? [],
            'data' => $state['data'] ?? [],
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_bundle_news_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, NewsPost $post, ValidatorInterface $validator): Response
    {
        $session = $request->getSession();
        $sessionKey = self::SESSION_FORM_STATE_KEY . $post->getId();

        if ($request->isMethod('POST')) {
            [$post, $errors] = $this->buildFromRequest($request, $post, $validator);
            if (empty($errors)) {
                $post->touch();
                $this->em->flush();
                $this->addFlash('success', 'News post updated.');

                return $this->redirectToRoute('admin_bundle_news_index');
            }

            $session->set($sessionKey, ['errors' => $errors, 'data' => $request->request->all()]);

            return $this->redirectToRoute('admin_bundle_news_edit', ['id' => $post->getId()]);
        }

        $state = $session->remove($sessionKey) ?? [];

        return $this->render('@News/admin/form.html.twig', [
            'mode' => 'Edit',
            'post' => $post,
            'errors' => $state['errors'] ?? [],
            'data' => $state['data'] ?? [],
        ]);
    }

    #[Route('/{id}/delete', name: 'admin_bundle_news_delete', methods: ['POST'])]
    public function delete(Request $request, NewsPost $post): Response
    {
        $this->em->remove($post);
        $this->em->flush();
        $this->addFlash('success', 'News post deleted.');
    

        return $this->redirectToRoute('admin_bundle_news_index');
    }

    /** @return array{NewsPost, array<string, string>} */
    private function buildFromRequest(Request $request, NewsPost $post, ValidatorInterface $validator): array
    {
        $title = trim((string) $request->request->get('title', ''));
        $excerpt = trim((string) $request->request->get('excerpt', ''));
        $content = trim((string) $request->request->get('content', ''));
        $publishedAtRaw = (string) $request->request->get('published_at', '');

        $values = new \ArrayObject([
            'title' => $title,
            'content' => $content,
            'published_at' => $publishedAtRaw,
        ]);
        $violations = $validator->validate($values, new ValidNewsPostRequest());

        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        if (empty($errors)) {
            $post->setTitle($title)
                ->setExcerpt($excerpt !== '' ? $excerpt : null)
                ->setContent($content)
                ->setPublishedAt($values['published_at']);
        }

        return [$post, $errors];
    }

    private function upsertSetting(string $key, string $name, string $value): void
    {
        $setting = $this->em->getRepository(AppSetting::class)->findOneBy(['settingKey' => $key]);
        if (!$setting instanceof AppSetting) {
            $setting = (new AppSetting())->setSettingKey($key)->setName($name);
            $this->em->persist($setting);
        }
        $setting->setSettingValue($value)->touch();
    }
}
