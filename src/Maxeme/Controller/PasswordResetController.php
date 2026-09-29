<?php

declare(strict_types=1);

namespace App\Maxeme\Controller;

use App\Maxeme\Security\PasswordResetOutcome;
use App\Maxeme\Service\FieldErrors;
use App\Maxeme\Service\PasswordResetService;
use App\Validation\Dto\NewPasswordRequest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Forgot password (legacy FOSUserBundle resetting pages, CNSUserBundle ResettingController).
 * Public: `^/admin/resetting` is PUBLIC_ACCESS in security.yaml; the token is the credential.
 */
#[Route('/admin/resetting', name: 'maxeme_resetting_')]
final class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetService $passwordReset,
    ) {
    }

    #[Route('/request', name: 'request', methods: ['GET', 'POST'])]
    public function request(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->render('maxeme/resetting/request.html.twig');
        }

        $username = trim((string) $request->request->get('username', ''));
        [$outcome, $user] = $this->passwordReset->request($username);

        return match ($outcome) {
            PasswordResetOutcome::UnknownUser => $this->render('maxeme/resetting/request.html.twig', ['invalidUsername' => $username]),
            PasswordResetOutcome::AlreadyRequested => $this->render('maxeme/resetting/check_email.html.twig', ['alreadyRequested' => true, 'hours' => $this->passwordReset->windowHours()]),
            PasswordResetOutcome::Sent => $this->redirectToRoute('maxeme_resetting_check_email', ['email' => $user?->getEmail()]),
        };
    }

    #[Route('/check-email', name: 'check_email', methods: ['GET'])]
    public function checkEmail(Request $request): Response
    {
        $email = trim((string) $request->query->get('email', ''));
        if ($email === '') {
            return $this->redirectToRoute('maxeme_resetting_request');
        }

        return $this->render('maxeme/resetting/check_email.html.twig', ['email' => $email, 'alreadyRequested' => false]);
    }

    #[Route('/reset/{token}', name: 'reset', methods: ['GET', 'POST'])]
    public function reset(string $token, Request $request, ValidatorInterface $validator, Security $security): Response
    {
        $user = $this->passwordReset->findByToken($token)
            ?? throw new NotFoundHttpException(sprintf('The user with "confirmation token" does not exist for value "%s"', $token));

        if (!$this->passwordReset->hasValidToken($user)) {
            return $this->redirectToRoute('maxeme_resetting_request');
        }

        $errors = [];
        if ($request->isMethod('POST')) {
            $password = new NewPasswordRequest(
                (string) $request->request->get('password', ''),
                (string) $request->request->get('password_confirm', ''),
            );
            $errors = FieldErrors::from($validator->validate($password));

            if ($errors === []) {
                $this->passwordReset->reset($user, $password->password);
                $security->login($user, 'form_login', 'admin');
                $this->addFlash('success', 'The password has been reset successfully.');

                return $this->redirectToRoute('maxeme_profile_show');
            }
        }

        return $this->render('maxeme/resetting/reset.html.twig', [
            'token' => $token,
            'user' => $user,
            'errors' => $errors,
        ], new Response(status: $errors === [] ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
