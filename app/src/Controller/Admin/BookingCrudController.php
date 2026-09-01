<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Enum\BookingStatus;
use App\Entity\Booking;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sessions members have booked with coaches.
 *
 * Read-mostly, for the same reason orders are: a booking is an agreement
 * between two people, and staff hand-writing one into existence would mean a
 * member turning up to an hour nobody agreed to. There is no NEW, EDIT or
 * DELETE - disabled rather than merely removed, because removing a button
 * leaves /admin/booking/new answering.
 *
 * The one thing reception does is archive: marking a confirmed hour that has
 * already passed as completed, which is the transition that happens off the
 * internet.
 *
 * @extends AbstractCrudController<Booking>
 */
final class BookingCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly ClockInterface $clock,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Booking::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Booking')
            ->setEntityLabelInPlural('Bookings')
            ->setDefaultSort(['startsAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $complete = Action::new('complete', 'Mark completed', 'fa fa-check-double')
            ->linkToCrudAction('complete')
            ->displayIf(fn (Booking $booking): bool => BookingStatus::CONFIRMED === $booking->getStatus()
                && !$booking->isUpcoming($this->clock->now()));

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $complete)
            ->add(Crud::PAGE_DETAIL, $complete);
    }

    /**
     * @param AdminContext<Booking> $context
     */
    #[AdminRoute('/{entityId}/complete', name: 'complete')]
    public function complete(AdminContext $context): Response
    {
        $booking = $context->getEntity()->getInstance();
        $now = $this->clock->now();

        if ($booking instanceof Booking
            && BookingStatus::CONFIRMED === $booking->getStatus()
            && !$booking->isUpcoming($now)
        ) {
            $booking->setStatus(BookingStatus::COMPLETED);
            $this->entityManager->flush();
            $this->addFlash('success', 'Session marked as completed.');
        }

        return $this->redirect(
            $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl()
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('trainer');
        yield AssociationField::new('user')->setLabel('Member');
        yield DateTimeField::new('startsAt')->setFormTypeOption('disabled', true);
        yield DateTimeField::new('endsAt')->hideOnIndex()->setFormTypeOption('disabled', true);
        yield ChoiceField::new('status')
            ->setChoices(BookingStatus::cases())
            ->setFormTypeOption('disabled', true)
            ->setHelp('Coaches confirm and decline; members cancel. Staff only archive a past session.');
        yield MoneyField::new('pricePaidCents')
            ->setCurrency('EUR')
            ->setStoredAsCents()
            ->setLabel('Price')
            ->setFormTypeOption('disabled', true);
        yield TextareaField::new('notes')->onlyOnDetail();
        yield DateTimeField::new('createdAt')->hideOnForm()->hideOnIndex();
        yield DateTimeField::new('respondedAt')->hideOnForm()->hideOnIndex();
    }
}
