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
use App\Service\CloudinaryImageUploader;
use Doctrine\ORM\EntityManagerInterface;
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
            $entityInstance->setImg($this->imageUploader->uploadFilmPoster($entityInstance->getImg()));
        }

        parent::updateEntity($entityManager, $entityInstance);
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
