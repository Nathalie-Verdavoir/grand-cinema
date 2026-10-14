<?php

namespace App\Controller\Admin;

use App\Service\AdminKpiCalculator;
use DateTimeImmutable;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[AdminRoute(path: '/kpis', name: 'kpis')]
final class KpiController extends AbstractController
{
    #[AdminRoute(path: '', name: 'index', options: ['methods' => ['GET']])]
    public function index(Request $request, AdminKpiCalculator $calculator): Response
    {
        $today = new DateTimeImmutable('today');
        $from = $this->parseDate($request->query->get('from'), $today->modify('first day of this month'));
        $to = $this->parseDate($request->query->get('to'), $today);
        if ($from > $to) {
            throw new BadRequestHttpException('La date de début doit précéder la date de fin.');
        }

        $until = $to->modify('+1 day');
        $annualYear = (int) $to->format('Y');
        $annualFrom = $to->setDate($annualYear, 1, 1)->setTime(0, 0);
        $annualUntil = $annualFrom->modify('+1 year');
        $previousFrom = $this->sameDayPreviousMonth($from);
        $previousTo = $this->sameDayPreviousMonth($to);

        return $this->render('admin/kpis.html.twig', [
            'from' => $from,
            'to' => $to,
            'kpis' => $calculator->calculate($from, $until),
            'previousFrom' => $previousFrom,
            'previousTo' => $previousTo,
            'previousMonthKpis' => $calculator->calculate($previousFrom, $previousTo->modify('+1 day')),
            'annualYear' => $annualYear,
            'annualKpis' => $calculator->calculate($annualFrom, $annualUntil),
        ]);
    }

    private function sameDayPreviousMonth(DateTimeImmutable $date): DateTimeImmutable
    {
        $previousMonth = $date->modify('first day of this month')->modify('-1 month');
        $day = min((int) $date->format('j'), (int) $previousMonth->format('t'));

        return $previousMonth
            ->setDate((int) $previousMonth->format('Y'), (int) $previousMonth->format('n'), $day)
            ->setTime((int) $date->format('G'), (int) $date->format('i'), (int) $date->format('s'));
    }

    private function parseDate(mixed $value, DateTimeImmutable $default): DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return $default;
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            throw new BadRequestHttpException('La période sélectionnée est invalide.');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (false !== $errors && (0 !== $errors['warning_count'] || 0 !== $errors['error_count'])) || $date->format('Y-m-d') !== $value) {
            throw new BadRequestHttpException('La période sélectionnée est invalide.');
        }

        return $date;
    }
}
