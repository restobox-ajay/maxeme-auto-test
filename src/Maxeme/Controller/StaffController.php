<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Entity\AdminUser;
use App\Maxeme\Dto\StaffAccountRequest;
use App\Maxeme\Security\Attribute\RequiresPermission;
use App\Maxeme\Security\Permission;
use App\Maxeme\Security\StaffAccountVoter;
use App\Maxeme\Security\StaffRole;
use App\Maxeme\Service\StaffAccountService;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Config › Manage Admins (legacy CNSUserBundle ManageController + FOS registration).
 *
 * Difference from the legacy app, on purpose: adding an account no longer signs the creator in as
 * the new user; the "confirmed" page is a modal on this screen instead.
 */
#[Route('/admin/staff', name: 'maxeme_staff_')]
final class StaffController extends AbstractController
{
    /** Carries the new username to the "created successfully" modal after the redirect. */
    public const FLASH_CREATED = 'maxeme_staff_created';

    public function __construct(
        private readonly StaffAccountService $accounts,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    #[RequiresPermission(Permission::STAFF_VIEW)]
    public function index(): Response
    {
        return $this->renderIndex(new StaffAccountRequest(), []);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[RequiresPermission(Permission::STAFF_CREATE)]
    public function create(Request $request): Response
    {
        $account = StaffAccountRequest::fromRequest($request);
        $errors = $this->accounts->validate($account);

        if ($errors !== []) {
            return $this->renderIndex($account, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->accounts->create($account);
        $this->addFlash(self::FLASH_CREATED, (string) $user->getUsername());

        return $this->redirectToRoute('maxeme_staff_index');
    }

    #[Route('/{id}/deactivate', name: 'deactivate', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[RequiresPermission(Permission::STAFF_EDIT)]
    public function deactivate(#[MapEntity] AdminUser $user, #[CurrentUser] AdminUser $actor): JsonResponse
    {
        if (!$this->isGranted(StaffAccountVoter::MANAGE, $user)) {
            return $this->json(['message' => 'Only a Super Admin can change a Super Admin account.'], Response::HTTP_FORBIDDEN);
        }

        try {
            $this->accounts->deactivate($user, $actor);
        } catch (\DomainException $exception) {
            return $this->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        return $this->json(['message' => sprintf('"%s" has been de-activated.', $user->getUsername() ?? $user->getEmail())]);
    }

    /** @param array<string, string> $errors */
    private function renderIndex(StaffAccountRequest $account, array $errors, int $status = Response::HTTP_OK): Response
    {
        return $this->render('maxeme/staff/index.html.twig', [
            'users' => $this->accounts->activeAccounts(),
            'account' => $account,
            'errors' => $errors,
            'assignableRoles' => StaffRole::assignable(),
        ], new Response(status: $status));
    }
}
