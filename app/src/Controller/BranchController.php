<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\FloorPlan\FloorPlanBuilder;
use App\Domain\OpeningSchedule;
use App\Entity\Branch;
use App\Entity\FloorZone;
use App\Repository\BranchRepository;
use App\Repository\FloorZoneRepository;
use App\Repository\TrainerRepository;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public branch pages: a map of every Riga location, and one page per
 * branch carrying its hours, its coaches and its clickable floor plan.
 */
#[Route('/{_locale}/branches', requirements: ['_locale' => 'en|lv|ru'])]
final class BranchController extends AbstractController
{
    #[Route('', name: 'branch_index', methods: ['GET'])]
    public function index(Request $request, BranchRepository $branches): Response
    {
        $all = $branches->findActiveWithZones();
        $now = new DateTimeImmutable();

        return $this->render('branch/index.html.twig', [
            'branches' => $all,
            'schedules' => $this->schedulesFor($all),
            'now' => $now,
            'today' => (int) $now->format('N'),
            // Leaflet reads the branches off a data attribute rather than from
            // an inline <script>, which keeps the CSP surface to zero.
            'map_markers' => $this->markers($all, $now),
        ]);
    }

    #[Route('/{slug}', name: 'branch_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function show(
        string $slug,
        BranchRepository $branches,
        FloorZoneRepository $floorZones,
        TrainerRepository $trainers,
        FloorPlanBuilder $planBuilder,
    ): Response {
        $branch = $branches->findOneActiveBySlug($slug);

        if (null === $branch) {
            throw $this->createNotFoundException(sprintf('No active branch named "%s".', $slug));
        }

        $zones = $floorZones->findForBranchWithEquipment($branch);
        $now = new DateTimeImmutable();

        return $this->render('branch/show.html.twig', [
            'branch' => $branch,
            'zones' => $zones,
            // Rooms come back in zone order, so the template can pair them by index.
            'plan' => $planBuilder->build(array_map(
                static fn (FloorZone $zone): string => $zone->getSvgId(),
                $zones,
            )),
            'schedule' => OpeningSchedule::fromArray($branch->getOpeningHours()),
            'now' => $now,
            'trainers' => $trainers->findActiveForBranch($branch),
        ]);
    }

    /**
     * @param list<Branch> $branches
     *
     * @return array<string, OpeningSchedule> keyed by branch slug
     */
    private function schedulesFor(array $branches): array
    {
        $schedules = [];

        foreach ($branches as $branch) {
            $schedules[$branch->getSlug()] = OpeningSchedule::fromArray($branch->getOpeningHours());
        }

        return $schedules;
    }

    /**
     * @param list<Branch> $branches
     *
     * @return list<array{slug: string, name: string, lat: float, lng: float, address: string, hours: string|null, open: bool, url: string}>
     */
    private function markers(array $branches, DateTimeImmutable $now): array
    {
        $today = (int) $now->format('N');

        return array_map(function (Branch $branch) use ($today, $now): array {
            $schedule = OpeningSchedule::fromArray($branch->getOpeningHours());
            $period = $schedule->forDay($today);

            return [
                'slug' => $branch->getSlug(),
                'name' => $branch->getName(),
                'lat' => $branch->getLatitude(),
                'lng' => $branch->getLongitude(),
                'address' => sprintf('%s, %s %s', $branch->getAddressLine(), $branch->getCity(), $branch->getPostalCode()),
                'hours' => null === $period ? null : $period->open.'–'.$period->close,
                'open' => $schedule->isOpenAt($now),
                'url' => $this->generateUrl('branch_show', ['slug' => $branch->getSlug()]),
            ];
        }, $branches);
    }
}
