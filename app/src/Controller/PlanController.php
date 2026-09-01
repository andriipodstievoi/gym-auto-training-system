<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\TrainingPlan;
use App\Entity\User;
use App\Plan\PlanService;
use App\Plan\PlanServiceUnavailable;
use App\Repository\TrainingPlanRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Reading a programme back: the page, the PDF, and the list of both.
 *
 * A plan is somebody's health information, so ownership is checked on every
 * action rather than relied on from the URL being hard to guess. It is asked
 * of the plan itself - see TrainingPlan::isVisibleTo - because the second
 * action is where a copy-pasted check gets forgotten.
 */
#[IsGranted('ROLE_USER')]
final class PlanController extends AbstractController
{
    #[Route(
        '/{_locale}/plan/{id}',
        name: 'plan_show',
        requirements: ['_locale' => 'en|lv|ru', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function show(int $id, #[CurrentUser] User $user, TrainingPlanRepository $plans): Response
    {
        $plan = $this->find($id, $user, $plans);

        if (null === $plan) {
            return $this->refuse();
        }

        if ($plan->isReferral()) {
            return $this->render('plan/referral.html.twig', ['plan' => $plan]);
        }

        return $this->render('plan/show.html.twig', ['plan' => $plan]);
    }

    /**
     * The same programme as a document, rendered by the service.
     *
     * The bytes come back from ai-service rather than being laid out here: the
     * PDF and the page have to be the same programme, and the only way to
     * guarantee that is for one engine to produce both from the same answers.
     */
    #[Route(
        '/{_locale}/plan/{id}/pdf',
        name: 'plan_pdf',
        requirements: ['_locale' => 'en|lv|ru', 'id' => '\d+'],
        methods: ['GET'],
    )]
    public function pdf(
        int $id,
        #[CurrentUser]
        User $user,
        TrainingPlanRepository $plans,
        PlanService $planService,
    ): Response {
        $plan = $this->find($id, $user, $plans);

        if (null === $plan) {
            return $this->refuse();
        }

        // A referral is not a document. The page offers no download button for
        // one; this is the hand-typed URL.
        if ($plan->isReferral()) {
            return $this->redirectToRoute('plan_show', ['id' => $plan->getId()]);
        }

        try {
            $pdf = $planService->pdf($plan);
        } catch (PlanServiceUnavailable) {
            // The programme is stored and the page still renders it. Only the
            // document needs the service, so a service that is down costs a
            // download rather than a 500.
            $this->addFlash('error', 'assessment.flash.unavailable');

            return $this->redirectToRoute('plan_show', ['id' => $plan->getId()]);
        }

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                sprintf('speks-training-plan-%d.pdf', $plan->getId()),
            ),
        ]);
    }

    #[Route(
        '/{_locale}/account/plans',
        name: 'app_account_plans',
        requirements: ['_locale' => 'en|lv|ru'],
        methods: ['GET'],
    )]
    public function mine(#[CurrentUser] User $user, TrainingPlanRepository $plans): Response
    {
        return $this->render('account/plans.html.twig', [
            'plans' => $plans->findForMember($user),
        ]);
    }

    /**
     * A plan this member is allowed to see, or null if it belongs to somebody
     * else. A plan that does not exist at all is a 404.
     */
    private function find(int $id, User $user, TrainingPlanRepository $plans): ?TrainingPlan
    {
        $plan = $plans->find($id);

        if (null === $plan) {
            throw $this->createNotFoundException(sprintf('No training plan with id %d.', $id));
        }

        return $plan->isVisibleTo($user) ? $plan : null;
    }

    /**
     * Somebody else's programme, answered the way a cancelled booking that is
     * not yours is: told no, and sent to the list of the ones that are.
     */
    private function refuse(): Response
    {
        $this->addFlash('error', 'assessment.flash.not_allowed');

        return $this->redirectToRoute('app_account_plans');
    }
}
