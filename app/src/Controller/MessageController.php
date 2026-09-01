<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\Message;
use App\Entity\User;
use App\Mailer\MemberMailer;
use App\Repository\ConversationRepository;
use App\Repository\TrainerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Messages between members and coaches.
 *
 * One controller for both sides, and the same two templates: a thread does not
 * change shape depending on who is reading it, and two near-identical screens
 * would drift apart the first time one of them was fixed.
 *
 * Who may read a thread is decided by Conversation::involves(), which asks the
 * data rather than the roles - a coach is only a coach of their own threads.
 */
#[Route('/{_locale}/messages', requirements: ['_locale' => 'en|lv|ru'])]
#[IsGranted('ROLE_USER')]
final class MessageController extends AbstractController
{
    private const string CSRF_ID = 'message';

    #[Route('', name: 'message_index', methods: ['GET'])]
    public function index(
        #[CurrentUser]
        User $user,
        ConversationRepository $conversations,
    ): Response {
        return $this->render('message/index.html.twig', [
            'conversations' => $conversations->findForParticipant($user),
        ]);
    }

    #[Route('/{id}', name: 'message_thread', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function thread(
        int $id,
        #[CurrentUser]
        User $user,
        ConversationRepository $conversations,
        EntityManagerInterface $entityManager,
        ClockInterface $clock,
    ): Response {
        $conversation = $this->threadFor($id, $user, $conversations);

        // Opening the thread is what "read" means. Only flush when something
        // actually changed - most page views change nothing.
        if ($conversation->markReadFor($user, $clock->now()) > 0) {
            $entityManager->flush();
        }

        return $this->render('message/thread.html.twig', [
            'conversation' => $conversation,
        ]);
    }

    /**
     * Writes into a thread, opening it if this is the first message. The
     * trainer slug is what a profile's "message this coach" button posts; the
     * conversation id is what a reply posts.
     */
    #[Route('/send', name: 'message_send', methods: ['POST'], priority: 1)]
    public function send(
        Request $request,
        #[CurrentUser]
        User $user,
        ConversationRepository $conversations,
        TrainerRepository $trainers,
        EntityManagerInterface $entityManager,
        MemberMailer $mailer,
        ClockInterface $clock,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'message.flash.invalid_token');

            return $this->redirectToRoute('message_index');
        }

        $body = $request->request->get('body');

        if (!is_string($body) || '' === trim($body)) {
            $this->addFlash('error', 'message.flash.invalid_token');

            return $this->redirectToRoute('message_index');
        }

        $conversation = $this->resolveConversation($request, $user, $conversations, $trainers, $clock);

        $message = new Message($conversation, $user, mb_substr(trim($body), 0, 2000), $clock->now());

        $entityManager->persist($conversation);
        $entityManager->persist($message);
        $entityManager->flush();

        $mailer->sendNewMessage($message, $conversation->counterpartOf($user));
        $this->addFlash('success', 'message.flash.sent');

        return $this->redirectToRoute('message_thread', ['id' => $conversation->getId()]);
    }

    /**
     * The thread the form is writing into: an existing one by id, or the one
     * thread this member and this coach share, created on first contact.
     */
    private function resolveConversation(
        Request $request,
        User $user,
        ConversationRepository $conversations,
        TrainerRepository $trainers,
        ClockInterface $clock,
    ): Conversation {
        $id = $request->request->get('conversation');

        if (is_string($id) && 1 === preg_match('/^\d+$/', $id)) {
            return $this->threadFor((int) $id, $user, $conversations);
        }

        $trainer = $trainers->findOneActiveBySlug((string) $request->request->get('trainer', ''));

        if (null === $trainer) {
            throw $this->createNotFoundException('There is nobody to write to.');
        }

        // A coach writing to their own profile would open a thread with
        // themselves; there is nothing sensible to do with that.
        if ($trainer->getUser()?->getId() === $user->getId()) {
            throw $this->createAccessDeniedException('A coach cannot open a thread with themselves.');
        }

        return $conversations->findOnePair($trainer, $user)
            ?? new Conversation($trainer, $user, $clock->now());
    }

    /**
     * A thread this account is actually in.
     */
    private function threadFor(int $id, User $user, ConversationRepository $conversations): Conversation
    {
        $conversation = $conversations->find($id);

        // 404 rather than 403 for a thread that is not theirs: confirming a
        // conversation exists is already telling them something.
        if (null === $conversation || !$conversation->involves($user)) {
            throw $this->createNotFoundException('No conversation of yours with that id.');
        }

        return $conversation;
    }
}
