<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Domain\Enum\OrderStatus;
use App\Entity\Order;
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
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orders the shop has taken.
 *
 * Read-only apart from one button. There is no NEW, no EDIT and no DELETE: an
 * order is the record of a payment, and the only things allowed to create or
 * settle one are checkout and the webhook that confirms it. Staff get exactly
 * one transition, PAID to FULFILLED, which is the one thing that happens off
 * the internet - somebody handing a parcel over a counter. Nothing here can
 * fabricate a payment.
 *
 * @extends AbstractCrudController<Order>
 */
final class OrderCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Order::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Order')
            ->setEntityLabelInPlural('Orders')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $fulfil = Action::new('fulfil', 'Mark fulfilled', 'fa fa-box-open')
            ->linkToCrudAction('fulfil')
            ->displayIf(static fn (Order $order): bool => OrderStatus::PAID === $order->getStatus());

        // Disabled rather than merely hidden: removing a button from a page
        // leaves its URL working, and /admin/order/new must refuse rather
        // than let somebody hand-type a sale into existence.
        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::BATCH_DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $fulfil)
            ->add(Crud::PAGE_DETAIL, $fulfil);
    }

    /**
     * The one transition staff may make, and only from PAID.
     *
     * @param AdminContext<Order> $context
     */
    #[AdminRoute('/{entityId}/fulfil', name: 'fulfil')]
    public function fulfil(AdminContext $context): Response
    {
        $order = $context->getEntity()->getInstance();

        if ($order instanceof Order && OrderStatus::PAID === $order->getStatus()) {
            $order->setStatus(OrderStatus::FULFILLED);
            $this->entityManager->flush();
            $this->addFlash('success', 'Order marked as fulfilled.');
        }

        return $this->redirect(
            $this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->generateUrl()
        );
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('reference')->setFormTypeOption('disabled', true);
        yield AssociationField::new('user');
        yield EmailField::new('email')->hideOnIndex();
        yield ChoiceField::new('status')
            ->setChoices(OrderStatus::cases())
            ->setFormTypeOption('disabled', true)
            ->setHelp('Only the Stripe webhook may settle an order; staff move a paid one to fulfilled.');
        yield MoneyField::new('totalCents')
            ->setCurrency('EUR')
            ->setStoredAsCents()
            ->setLabel('Total')
            ->setFormTypeOption('disabled', true);
        yield AssociationField::new('items')->onlyOnDetail();
        yield DateTimeField::new('createdAt')->hideOnForm();
        yield DateTimeField::new('paidAt')->hideOnForm();
        yield TextField::new('stripeCheckoutSessionId')
            ->setLabel('Stripe session')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();
        yield TextField::new('stripePaymentIntentId')
            ->setLabel('Stripe payment intent')
            ->setFormTypeOption('disabled', true)
            ->onlyOnDetail();
    }
}
