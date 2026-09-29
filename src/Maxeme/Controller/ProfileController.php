<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Entity\AdminUser;
use App\Maxeme\Dto\ProfileUpdateRequest;
use App\Maxeme\Service\FieldErrors;
use App\Maxeme\Service\StaffAccountService;
use App\Validation\Dto\PasswordChangeRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** User menu › My Profile, Edit Profile, Change Password (legacy FOSUserBundle profile pages). */
#[Route('/admin/my-profile', name: 'maxeme_profile_')]
final class ProfileController extends AbstractController
{
    public function __construct(
        private readonly StaffAccountService $accounts,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(#[CurrentUser] AdminUser $user): Response
    {
        return $this->render('maxeme/profile/show.html.twig', ['user' => $user]);
    }

    #[Route('/edit', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] AdminUser $user): Response
    {
        $profile = ProfileUpdateRequest::fromUser($user);
        $errors = [];

        if ($request->isMethod('POST')) {
            $profile = ProfileUpdateRequest::fromRequest($request);
            $errors = $this->accounts->validate($profile, $user);

            if ($errors === []) {
                $this->accounts->updateIdentity($user, $profile);
                $this->addFlash('success', 'The profile has been updated.');

                return $this->redirectToRoute('maxeme_profile_show');
            }
        }

        return $this->render('maxeme/profile/edit.html.twig', [
            'profile' => $profile,
            'errors' => $errors,
        ], new Response(status: $errors === [] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    #[Route('/change-password', name: 'change_password', methods: ['GET', 'POST'])]
    public function changePassword(Request $request, #[CurrentUser] AdminUser $user, ValidatorInterface $validator): Response
    {
        $errors = [];

        if ($request->isMethod('POST')) {
            $change = new PasswordChangeRequest(
                $user,
                (string) $request->request->get('current_password', ''),
                (string) $request->request->get('new_password', ''),
                (string) $request->request->get('confirm_new_password', ''),
            );
            $errors = FieldErrors::from($validator->validate($change));

            if ($errors === []) {
                $this->accounts->changePassword($user, $change->newPassword);
                $this->addFlash('success', 'The password has been changed.');

                return $this->redirectToRoute('maxeme_profile_show');
            }
        }

        return $this->render('maxeme/profile/change_password.html.twig', [
            'errors' => $errors,
        ], new Response(status: $errors === [] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
