<?php

namespace App\Controller\Admin;

use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function index(): Response
    {
        return $this->redirectToRoute('admin_kpis_index');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('Grand Cinema');
    }

    public function configureAssets(): Assets
    {
        return parent::configureAssets()
            ->addWebpackEncoreEntry('admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Tableau de bord', 'fa fa-home');
        yield MenuItem::linkToRoute('Indicateurs', 'fas fa-chart-line', 'admin_kpis_index');
        yield MenuItem::linkToRoute('Séances à forte occupation', 'fas fa-exclamation-triangle', 'admin_high_occupancy_index');
        yield MenuItem::linkToRoute('Caisse', 'fas fa-cash-register', 'admin_caisse_index');
        yield MenuItem::linkToRoute('Réservations guichet', 'fas fa-receipt', 'admin_reservations_guichet_index');
        // yield MenuItem::linkToCrud('The Label', 'fas fa-list', EntityClass::class);
        yield MenuItem::linkTo(FilmCrudController::class, 'Films', 'fas fa-film')->setAction(Action::INDEX);
        yield MenuItem::linkTo(TarifCrudController::class, 'Tarifs', 'fas fa-euro-sign')->setAction(Action::INDEX);
        yield MenuItem::linkTo(SalleCrudController::class, 'Salles', 'fas fa-door-open')->setAction(Action::INDEX);
        yield MenuItem::linkTo(SeanceCrudController::class, 'Séances', 'fas fa-calendar-alt')->setAction(Action::INDEX);
        yield MenuItem::linkTo(ReservationCrudController::class, 'Réservations', 'fas fa-ticket-alt')->setAction(Action::INDEX);
        yield MenuItem::linkTo(BilletCrudController::class, 'Billets', 'fas fa-ticket')->setAction(Action::INDEX);

        yield MenuItem::linkTo(UserCrudController::class, 'Utilisateurs', 'fas fa-users')->setAction(Action::INDEX);



    }
}
