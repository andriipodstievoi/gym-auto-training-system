<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Mailer\MemberMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

final class RegistrationController extends AbstractController
{
    #[Route('/{_locale}/register', name: 'app_register', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET', 'POST'])]
    public function register(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        MemberMailer $mailer,
        Security $security,
    ): Response {
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('app_account');
        }

        $user = new User();
        // Whichever language they signed up in is the one we write to them in,
        // until they say otherwise on their profile.
        $user->setLocale($request->getLocale());

        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $form->get('plainPassword')->getData();
            $user->setPassword($passwordHasher->hashPassword($user, \is_string($plainPassword) ? $plainPassword : ''));

            $entityManager->persist($user);
            $entityManager->flush();

            $mailer->sendWelcome($user);

            // Straight in rather than bouncing them to a login form they just
            // typed the same password into.
            $security->login($user, 'form_login', 'main');

            $this->addFlash('success', 'register.flash.welcome');

            return $this->redirectToRoute('app_account');
        }

        return $this->render('security/register.html.twig', [
            'form' => $form,
        ]);
    }
}
