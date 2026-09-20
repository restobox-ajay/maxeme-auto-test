<?php

declare(strict_types=1);

namespace Number1GuestCoverPageBundle\Controller\Admin;

use App\Twig\SandboxedTemplateRenderer;
use App\Repository\BundleStatusRepository;
use App\Validation\Constraint\ValidGuestCoverPageTemplateSource;
use Number1GuestCoverPageBundle\Service\GuestCoverPageTemplateStore;
use Number1GuestCoverPageBundle\TemplateOverride\CustomerLoginTemplateOverrideProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Twig\Error\Error as TwigError;

/**
 * Lets an admin edit the login page's Twig source directly from the admin UI, stored via
 * GuestCoverPageTemplateStore (an AppSetting row) and picked up by
 * CustomerLoginTemplateOverrideProvider::getTemplateSource() ahead of the bundle's shipped
 * templates/login.html.twig file — same "DB override beats file default" pattern this app
 * already uses for EmailTemplate rows (see AuthController::register()/passwordReset()).
 */
#[Route('/admin/bundles/guest-cover-page')]
final class GuestCoverPageController extends AbstractController
{
    /** Sample context for previews and for the reference list rendered above the editor. */
    private const SAMPLE_CONTEXT = [
        'error' => null,
        'login_error_message' => null,
        'last_username' => '',
    ];

    #[Route('', name: 'admin_bundle_guest_cover_page_index', methods: ['GET'])]
    public function index(
        BundleStatusRepository $bundleStatusRepo,
        CustomerLoginTemplateOverrideProvider $provider,
        GuestCoverPageTemplateStore $store,
    ): Response {
        $customSource = $store->getCustomSource();

        return $this->render('@Number1GuestCoverPage/admin/index.html.twig', [
            'active' => $bundleStatusRepo->isActive($provider->getSource()),
            'overridePoint' => $provider->getPoint(),
            'overrideTemplate' => $provider->getTemplate(),
            'defaultTemplate' => 'customer/auth/login.html.twig',
            'templateSource' => $customSource ?? $store->getDefaultSource(),
            'isCustomized' => $customSource !== null,
            'errors' => [],
            'warnings' => [],
        ]);
    }

    #[Route('/save', name: 'admin_bundle_guest_cover_page_save', methods: ['POST'])]
    public function save(
        Request $request,
        GuestCoverPageTemplateStore $store,
        ValidatorInterface $validator,
        BundleStatusRepository $bundleStatusRepo,
        CustomerLoginTemplateOverrideProvider $provider,
    ): Response {
        if ((string) $request->request->get('action', 'save') === 'reset') {
            $store->reset();
            $this->addFlash('success', "Reset to the bundle's default template.");

            return $this->redirectToRoute('admin_bundle_guest_cover_page_index');
        }

        $source = (string) $request->request->get('template_source', '');
        $errors = $this->errorsFor($source, $validator);
        $warnings = $errors === [] ? $this->warningsFor($source) : [];

        if ($errors !== []) {
            // Re-render with the admin's attempted (unsaved) source and the errors inline,
            // rather than redirecting — a redirect would silently discard their edit.
            $customSource = $store->getCustomSource();

            return $this->render('@Number1GuestCoverPage/admin/index.html.twig', [
                'active' => $bundleStatusRepo->isActive($provider->getSource()),
                'overridePoint' => $provider->getPoint(),
                'overrideTemplate' => $provider->getTemplate(),
                'defaultTemplate' => 'customer/auth/login.html.twig',
                'templateSource' => $source,
                'isCustomized' => $customSource !== null,
                'errors' => $errors,
                'warnings' => $warnings,
            ], new Response(null, 422));
        }

        $store->save($source);

        if ($warnings !== []) {
            $this->addFlash('warn', 'Saved, but double check: ' . implode(' ', $warnings));
        } else {
            $this->addFlash('success', 'Template updated.');
        }

        return $this->redirectToRoute('admin_bundle_guest_cover_page_index');
    }

    /**
     * Renders the submitted (unsaved) source with sample data, in a new tab — never touches
     * GuestCoverPageTemplateStore, so it can never affect what customers see at /auth/login.
     */
    #[Route('/preview', name: 'admin_bundle_guest_cover_page_preview', methods: ['POST'])]
    public function preview(Request $request, SandboxedTemplateRenderer $templateRenderer, TokenStorageInterface $tokenStorage): Response
    {
        $source = (string) $request->request->get('template_source', '');

        try {
            $html = $this->renderAsGuest($source, $templateRenderer, $tokenStorage);
        } catch (TwigError $e) {
            return new Response(
                '<pre style="padding:24px;color:#b91c1c;white-space:pre-wrap;font:14px monospace;">Twig error — this was NOT saved:\n\n' . htmlspecialchars($e->getMessage()) . '</pre>',
                422,
            );
        }

        $banner = '<div style="position:sticky;top:0;z-index:9999;background:#111827;color:#fff;'
            . 'text-align:center;padding:8px 12px;font:600 13px system-ui,sans-serif;">'
            . 'PREVIEW — unsaved changes, not visible to customers</div>';
        $html = preg_replace('/<body([^>]*)>/', '<body$1>' . $banner, $html, 1) ?? $html;

        return new Response($html);
    }

    /** @return list<string> */
    private function errorsFor(string $source, ValidatorInterface $validator): array
    {
        $violations = $validator->validate($source, new ValidGuestCoverPageTemplateSource(self::SAMPLE_CONTEXT));

        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = (string) $violation->getMessage();
        }

        return $errors;
    }

    /** @return list<string> */
    private function warningsFor(string $source): array
    {
        $warnings = [];
        if (!str_contains($source, 'name="_username"')) {
            $warnings[] = 'No field named "_username" found — customers won\'t be able to enter their email.';
        }
        if (!str_contains($source, 'name="_password"')) {
            $warnings[] = 'No field named "_password" found — customers won\'t be able to enter their password.';
        }
        if (!str_contains($source, 'csrf_token(')) {
            $warnings[] = 'No csrf_token(...) call found — submitting the form will fail with a CSRF error.';
        }
        if (!str_contains($source, "path('customer_login')") && !str_contains($source, 'path("customer_login")')) {
            $warnings[] = 'Form action doesn\'t call path(\'customer_login\') — check the <form action="..."> attribute.';
        }

        return $warnings;
    }

    /**
     * Renders with the app.user global forced to null (a logged-out guest), regardless of
     * who's actually logged in. This runs under the admin firewall, so app.user would otherwise
     * resolve to the current AdminUser — but customer/_main/layout.html.twig (which this
     * template extends) assumes app.user is a CustomerUser|null (e.g. app.user.company), and
     * AdminUser has no such property, which crashes the render. The login page only ever renders
     * for guests anyway (AuthController::login() redirects an authenticated CustomerUser away
     * before it gets this far), so forcing a guest view is also the correct simulation, not just
     * a workaround. ValidGuestCoverPageTemplateSourceValidator does the same thing for save(),
     * since that's a separate call to the same sandboxed renderer.
     *
     * @throws TwigError
     */
    private function renderAsGuest(string $source, SandboxedTemplateRenderer $templateRenderer, TokenStorageInterface $tokenStorage): string
    {
        $originalToken = $tokenStorage->getToken();
        $tokenStorage->setToken(null);

        try {
            return $templateRenderer->render($source, self::SAMPLE_CONTEXT);
        } finally {
            $tokenStorage->setToken($originalToken);
        }
    }
}
