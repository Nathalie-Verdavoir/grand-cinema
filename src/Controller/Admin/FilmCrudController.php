<?php

namespace App\Controller\Admin;

use App\Entity\Film;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use App\Service\CloudinaryImageUploader;
use App\Controller\Admin\SeanceCrudController;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\RedirectResponse;
class FilmCrudController extends AbstractCrudController
{
    private $httpClient;
    private CloudinaryImageUploader $imageUploader;

    public function __construct(CloudinaryImageUploader $imageUploader)
    {
        $this->imageUploader = $imageUploader;
    }
    public static function getEntityFqcn(): string
    {
        return Film::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setPaginatorPageSize(10)
            ->setSearchFields(['title'])
            ->overrideTemplate('crud/edit', 'admin/film/edit.html.twig')
            ->overrideTemplate('crud/new', 'admin/film/new.html.twig')
            ->setFormOptions([
                'attr' => ['data-controller' => 'search-film']
            ]);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Film) {
            $entityInstance->setImg($this->imageUploader->uploadFilmPoster($entityInstance->getImg()));
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Film) {
            $originalImg = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['img'] ?? null;
            if (null === $originalImg || $entityInstance->getImg() !== $originalImg) {
                $entityInstance->setImg($this->imageUploader->uploadFilmPoster($entityInstance->getImg()));
            }
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    protected function getRedirectResponseAfterSave(\EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext $context, string $action): RedirectResponse
    {
        $submitButton = $context->getRequest()->request->all()['ea']['newForm']['btn'] ?? null;
        if ('saveAndGoToSchedule' === $submitButton) {
            $weekStart = (new DateTimeImmutable('today'))->modify('monday this week')->format('Y-m-d');
            $url = $this->container->get(AdminUrlGenerator::class)
                ->setController(SeanceCrudController::class)
                ->setAction(Action::INDEX)
                ->set('week', $weekStart)
                ->generateUrl();

            return $this->redirect($url);
        }

        return parent::getRedirectResponseAfterSave($context, $action);
    }

    public function configureFields(string $pageName): iterable
    {
        // Étape 1 : Recherche
        yield TextField::new('search_title', 'Rechercher un film (API)')
            ->setRequired(false)
            ->onlyOnForms()
            ->setFormTypeOption('mapped', false)
            ->setHtmlAttribute('data-search-film-target', 'searchInput')
            ->setHtmlAttribute('data-action', 'input->search-film#search');

        // Étape 2 : Liste des résultats (Corrigée)
        yield ChoiceField::new('api_results', 'Résultats trouvés')
            ->onlyOnForms()
            ->renderAsNativeWidget()
            ->setRequired(false)
            ->setFormTypeOption('mapped', false)
            ->setFormTypeOption('choices', []) // Toujours vide au départ
            ->setHtmlAttribute('data-search-film-target', 'resultsSelect')
            ->setHtmlAttribute('data-action', 'change->search-film#selectMovie');

        // Étape 3 : Vos vrais champs
        yield TextField::new('title', 'Titre final')
            ->setHtmlAttribute('data-search-film-target', 'titleField');

        yield TextareaField::new('description', 'Description')
            ->setRequired(false)
            ->setMaxLength(240)
            ->setHtmlAttribute('data-search-film-target', 'synopsisField');

        yield DateField::new('releaseDate', 'Date de sortie')
            ->setFormat('dd/MM/yyyy')
            ->setRequired(false)
            ->setHtmlAttribute('data-search-film-target', 'releaseDateField');

        yield BooleanField::new('programmable', 'Programmable');

        yield IntegerField::new('duration', 'Durée (minutes)')
            ->setRequired(false)
            ->setHtmlAttribute('data-search-film-target', 'durationField');

        yield ImageField::new('img', 'Affiche')
            ->setBasePath('')
            ->setRequired(false)
            ->onlyOnIndex()
        ;

        yield TextField::new('img', 'URL de l’affiche')
            ->setRequired(false)
            ->onlyOnForms()
            ->setFormTypeOption('row_attr.class', 'd-none')
            ->setHtmlAttribute('data-search-film-target', 'imageField')
            ->setHtmlAttribute('data-action', 'input->search-film#updateImagePreview');
    }
}
