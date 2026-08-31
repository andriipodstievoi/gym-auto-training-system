<?php

declare(strict_types=1);

namespace App\Controller;

use LogicException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    /**
     * Both renders the form and receives it: form_login is configured with the
     * same route for login_path and check_path, so the firewall intercepts the
     * POST before this method ever runs.
     */
    #[Route('/{_locale}/login', name: 'app_login', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Never executed - the firewall catches this path and clears the session.
     * The route has to exist so the router can generate a URL for it.
     */
    #[Route('/{_locale}/logout', name: 'app_logout', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET', 'POST'])]
    public function logout(): never
    {
        throw new LogicException('The logout firewall listener intercepts this route, so this method is unreachable.');
    }
}
