<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Assessment;
use App\Entity\TrainingPlan;
use App\Entity\User;
use App\Form\AssessmentFormType;
use App\Plan\MedicalReferralResult;
use App\Plan\PlanService;
use App\Plan\PlanServiceUnavailable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The questionnaire: the only way into the plan service.
 *
 * Two things happen here that look like duplication and are not.
 *
 * The PAR-Q+ gate runs on this side as well as inside the service. The service
 * would refuse a flagged assessment anyway - not making the call is the point.
 * A member who has just told us about a heart condition should not have those
 * answers put on a wire at all, and the referral they get back should not
 * depend on a second runtime being up.
 *
 * The plan is generated before anything is written. A member whose plan
 * service was not answering must not be left owning an assessment with no
 * programme attached: nothing on the site could ever show it to them, and the
 * account page would list a plan that is not one.
 */
final class AssessmentController extends AbstractController
{
    /**
     * Its own token id, like booking and cart. Not one of the stateless ids in
     * config/packages/csrf.yaml, so this one is session-backed.
     */
    private const string CSRF_ID = 'assessment';

    #[Route(
        '/{_locale}/assessment',
        name: 'assessment_start',
        requirements: ['_locale' => 'en|lv|ru'],
        methods: ['GET'],
    )]
    #[IsGranted('ROLE_USER')]
    public function start(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->render('assessment/form.html.twig', [
            'form' => $this->form($request, $user),
        ]);
    }

    #[Route(
        '/{_locale}/assessment',
        name: 'assessment_submit',
        requirements: ['_locale' => 'en|lv|ru'],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    public function submit(
        Request $request,
        #[CurrentUser]
        User $user,
        PlanService $planService,
        EntityManagerInterface $entityManager,
    ): Response {
        if (!$this->isCsrfTokenValid(self::CSRF_ID, (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'assessment.flash.invalid_token');

            return $this->redirectToRoute('assessment_start');
        }

        $form = $this->form($request, $user);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            // 422 rather than 200, set by render() because the form is in the
            // parameters. Answering an unusable submission with a 200 is what
            // makes a browser think the page it got back is the one it asked
            // for.
            return $this->render('assessment/form.html.twig', ['form' => $form]);
        }

        $assessment = $form->getData();

        // The gate, before the wire. A flagged assessment is answered from
        // here, with the same red flags and in the same stored shape the
        // service would have used.
        if ($assessment->hasRedFlags()) {
            return $this->store(
                $entityManager,
                $assessment,
                TrainingPlan::fromReferral($assessment, new MedicalReferralResult($assessment->getRedFlags())),
            );
        }

        try {
            $result = $planService->generate($assessment);
        } catch (PlanServiceUnavailable) {
            // Nothing is persisted: no assessment, no plan, no row anybody has
            // to clean up later. The form comes back holding every answer, so
            // "try again in a moment" costs a click rather than eighteen
            // fields.
            $this->addFlash('error', 'assessment.flash.unavailable');

            return $this->render(
                'assessment/form.html.twig',
                ['form' => $form],
                new Response(status: Response::HTTP_SERVICE_UNAVAILABLE),
            );
        }

        if ($result instanceof MedicalReferralResult) {
            // The two sides disagreed about the screening, which the gate
            // above should have made impossible. The member still gets the
            // referral rather than an error.
            return $this->store($entityManager, $assessment, TrainingPlan::fromReferral($assessment, $result));
        }

        $this->addFlash('success', 'assessment.flash.generated');

        return $this->store($entityManager, $assessment, $result);
    }

    /**
     * The answers and what came back, written together and shown.
     *
     * A referral is stored exactly like a programme is - the member has to be
     * able to come back to the answer they were given.
     */
    private function store(EntityManagerInterface $entityManager, Assessment $assessment, TrainingPlan $plan): Response
    {
        $entityManager->persist($assessment);
        $entityManager->persist($plan);
        $entityManager->flush();

        return $this->redirectToRoute('plan_show', ['id' => $plan->getId()]);
    }

    /**
     * A blank questionnaire for this member, in the language they are reading.
     *
     * The locale travels with the assessment rather than being read off
     * whatever request asks for the PDF months later, so it is taken from the
     * request rather than from the profile: somebody browsing in Russian is
     * answering in Russian, whatever their account says.
     *
     * @return FormInterface<Assessment>
     */
    private function form(Request $request, User $user): FormInterface
    {
        $assessment = (new Assessment($user))->setLocale($request->getLocale());

        return $this->createForm(AssessmentFormType::class, $assessment);
    }
}
