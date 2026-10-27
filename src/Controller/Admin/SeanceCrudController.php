<?php

namespace App\Controller\Admin;

use App\Entity\Seance;
use App\Service\SeancePlannerPageDataProvider;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\KeyValueStore;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class SeanceCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly SeancePlannerPageDataProvider $pageDataProvider,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Seance::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        $isPlanningFragmentRequest = 'XMLHttpRequest' === $this->requestStack->getCurrentRequest()?->headers->get('X-Requested-With');

        return $crud
            ->setEntityLabelInSingular('Séance')
            ->setEntityLabelInPlural('Séances')
            ->setPaginatorPageSize(10)
            ->overrideTemplate('crud/index', $isPlanningFragmentRequest
                ? 'admin/seance/planner_fragment.html.twig'
                : 'admin/seance/planner.html.twig');
    }

    #[Route('/admin/seances/planner-fragment', name: 'admin_seance_planner_fragment', methods: ['GET'])]
    public function plannerFragment(): Response
    {
        $responseParameters = KeyValueStore::new(['pageName' => Crud::PAGE_INDEX]);
        $responseParameters = $this->configureResponseParameters($responseParameters);

        return $this->render('admin/seance/planner_fragment.html.twig', $responseParameters->all());
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->disable(Action::NEW, Action::EDIT, Action::DELETE, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('film', 'Film')
            ->setFormTypeOption('choice_label', 'title');
        yield DateTimeField::new('dateTime', 'Début')
            ->setFormat('dd/MM/yyyy HH:mm');
        yield AssociationField::new('salle', 'Salle')
            ->setFormTypeOption('choice_label', 'name');
    }

    public function configureResponseParameters(KeyValueStore $responseParameters): KeyValueStore
    {
        if (Crud::PAGE_INDEX !== $responseParameters->get('pageName')) {
            return $responseParameters;
        }

        foreach ($this->pageDataProvider->build($this->requestStack->getCurrentRequest()) as $key => $value) {
            $responseParameters->set($key, $value);
        }

        return $responseParameters;
    }
}
