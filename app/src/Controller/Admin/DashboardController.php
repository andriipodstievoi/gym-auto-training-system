<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Back office for the gym staff.
 *
 * Access is currently gated by HTTP basic auth against a single in-memory
 * account - see config/packages/security.yaml. Real accounts and roles land
 * in M3, and this placeholder goes away then.
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(private readonly AdminUrlGenerator $adminUrlGenerator)
    {
    }

    public function index(): Response
    {
        return $this->redirect(
            $this->adminUrlGenerator->setController(BranchCrudController::class)->generateUrl()
        );
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('SPĒKS <span class="text-small">admin</span>')
            ->setFaviconPath('favicon.svg')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::section('Locations');
        yield MenuItem::linkTo(BranchCrudController::class, 'Branches', 'fa fa-location-dot');
        yield MenuItem::linkTo(FloorZoneCrudController::class, 'Floor zones', 'fa fa-vector-square');
        yield MenuItem::linkTo(EquipmentCrudController::class, 'Equipment', 'fa fa-dumbbell');

        yield MenuItem::section('Commerce');
        yield MenuItem::linkTo(MembershipPlanCrudController::class, 'Membership plans', 'fa fa-id-card');
        yield MenuItem::linkTo(ProductCategoryCrudController::class, 'Product categories', 'fa fa-tags');
        yield MenuItem::linkTo(ProductCrudController::class, 'Products', 'fa fa-box');

        yield MenuItem::section('People');
        yield MenuItem::linkTo(TrainerCrudController::class, 'Trainers', 'fa fa-user-tie');

        yield MenuItem::section('Training');
        yield MenuItem::linkTo(ExerciseCrudController::class, 'Exercise library', 'fa fa-list-check');

        yield MenuItem::section();
        yield MenuItem::linkToUrl('Back to the site', 'fa fa-arrow-left', '/');
    }
}
