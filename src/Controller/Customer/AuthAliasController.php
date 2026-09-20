<?php

namespace App\Controller\Customer;

use App\Entity\CustomerUser;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AuthAliasController extends AbstractCustomerController
{
    #[Route('/login', name: 'customer_login_alias', methods: ['GET'])]
    public function login(): Response
    {
        $user = $this->getUser();
        if ($user instanceof CustomerUser) {
            return $this->redirectToRoute('customer_home');
        }

        return $this->redirectToRoute('customer_login');
    }
}
