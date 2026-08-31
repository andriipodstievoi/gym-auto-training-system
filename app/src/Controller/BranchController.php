<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\FloorPlan\FloorPlan;
use App\Domain\FloorPlan\FloorPlanBuilder;
use App\Domain\FloorPlan\ZoneItem;
use App\Domain\FloorPlan\ZoneLayout;
use App\Domain\FloorPlan\ZoneLayoutBuilder;
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
        Request $request,
        BranchRepository $branches,
        FloorZoneRepository $floorZones,
        TrainerRepository $trainers,
        FloorPlanBuilder $planBuilder,
        ZoneLayoutBuilder $layoutBuilder,
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
            'floors' => $this->floors($zones, $planBuilder),
            'layouts' => $this->layouts($zones, $layoutBuilder, $request->getLocale()),
            'schedule' => OpeningSchedule::fromArray($branch->getOpeningHours()),
            'now' => $now,
            'trainers' => $trainers->findActiveForBranch($branch),
        ]);
    }

    /**
     * One overview plan per storey. Zones arrive ordered by floor, so grouping
     * is enough - and rooms come back in zone order, which is what lets the
     * template pair a room with its zone by index.
     *
     * @param list<FloorZone> $zones
     *
     * @return list<array{number: int, zones: list<FloorZone>, plan: FloorPlan}>
     */
    private function floors(array $zones, FloorPlanBuilder $planBuilder): array
    {
        /** @var array<int, list<FloorZone>> $byFloor */
        $byFloor = [];

        foreach ($zones as $zone) {
            $byFloor[$zone->getFloor()][] = $zone;
        }

        ksort($byFloor);

        $floors = [];

        foreach ($byFloor as $number => $onThisFloor) {
            $floors[] = [
                'number' => $number,
                'zones' => $onThisFloor,
                'plan' => $planBuilder->build(array_map(
                    static fn (FloorZone $zone): string => $zone->getSvgId(),
                    $onThisFloor,
                )),
            ];
        }

        return $floors;
    }

    /**
     * The detailed machine plan behind every zone, keyed by svgId. They are all
     * rendered up front so opening one is an instant swap rather than a request,
     * and so a reader without JavaScript still gets every one of them.
     *
     * @param list<FloorZone> $zones
     *
     * @return array<string, ZoneLayout>
     */
    private function layouts(array $zones, ZoneLayoutBuilder $layoutBuilder, string $locale): array
    {
        $layouts = [];

        foreach ($zones as $zone) {
            $items = [];

            foreach ($zone->getEquipment() as $item) {
                $items[] = new ZoneItem(
                    (string) $item->getId(),
                    $item->getName()->get($locale),
                    $item->getType(),
                    $item->getQuantity(),
                );
            }

            $layouts[$zone->getSvgId()] = $layoutBuilder->build($items);
        }

        return $layouts;
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
