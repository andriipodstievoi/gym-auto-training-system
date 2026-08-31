<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Form\Model\ChangePassword;
use App\Form\ProfileFormType;
use App\Repository\UserMembershipRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The member's own corner of the site: what they hold, and who they are.
 *
 * access_control already keeps anonymous visitors out of /{_locale}/account;
 * the attribute here repeats it so the rule is visible in the code too.
 */
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    #[Route('/{_locale}/account', name: 'app_account', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET'])]
    public function index(#[CurrentUser] User $user, UserMembershipRepository $memberships): Response
    {
        return $this->render('account/index.html.twig', [
            'current' => $memberships->findCurrentFor($user),
            'history' => $memberships->findHistoryFor($user),
        ]);
    }

    #[Route('/{_locale}/account/profile', name: 'app_account_profile', requirements: ['_locale' => 'en|lv|ru'], methods: ['GET', 'POST'])]
    public function profile(
        Request $request,
        #[CurrentUser]
        User $user,
        EntityManagerInterface $entityManager,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $profileForm = $this->createForm(ProfileFormType::class, $user);
        $profileForm->handleRequest($request);

        if ($profileForm->isSubmitted() && $profileForm->isValid()) {
            $entityManager->flush();
            $this->addFlash('success', 'account.profile.flash.saved');

            // Follow them into the language they just picked.
            return $this->redirectToRoute('app_account_profile', ['_locale' => $user->getLocale()]);
        }

        $passwordForm = $this->createForm(ChangePasswordFormType::class, new ChangePassword());
        $passwordForm->handleRequest($request);

        if ($passwordForm->isSubmitted() && $passwordForm->isValid()) {
            $change = $passwordForm->getData();

            $user->setPassword($passwordHasher->hashPassword($user, $change->newPassword));
            $entityManager->flush();
            $this->addFlash('success', 'account.password.flash.changed');

            return $this->redirectToRoute('app_account_profile');
        }

        return $this->render('account/profile.html.twig', [
            'profileForm' => $profileForm,
            'passwordForm' => $passwordForm,
        ]);
    }
}
