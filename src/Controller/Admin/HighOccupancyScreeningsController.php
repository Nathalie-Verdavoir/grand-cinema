<?php

namespace App\Controller\Admin;

use App\Service\HighOccupancyScreeningFinder;
use DateTimeImmutable;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/seances-forte-occupation', name: 'high_occupancy')]
final class HighOccupancyScreeningsController extends AbstractController
{
    #[AdminRoute(path: '', name: 'index', options: ['methods' => ['GET']])]
    public function index(HighOccupancyScreeningFinder $screeningFinder): Response
    {
        $from = new DateTimeImmutable();

        return $this->render('admin/high_occupancy_screenings.html.twig', [
            'from' => $from,
            'to' => $from->modify('+2 days'),
            'screenings' => $screeningFinder->findBetween($from, $from->modify('+2 days')),
        ]);
    }
}
