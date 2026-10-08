// js/admin/search-film_controllers.js
// Stimulus controller pour la recherche de films via l'API TMDB

import { Controller } from '@hotwired/stimulus';
export default class extends Controller {
    static targets = ["searchInput", "resultsSelect", "titleField", "synopsisField", "releaseDateField", "imageField"];

    // Variable pour stocker les résultats complets de l'API temporairement
    filmsData = [];

    connect() {
        this.savedImageUrl = this.imageFieldTarget.value.trim();
        this.createImagePreview();
        this.updateSavedImagePreview();

        if (!this.hasResultsSelectTarget) {
            const resultsSelect = this.element.querySelector('select[name$="[api_results]"]');
            if (resultsSelect) {
                resultsSelect.dataset.searchFilmTarget = 'resultsSelect';
                resultsSelect.dataset.action = 'change->search-film#selectMovie';
            }
        }
    }

    createImagePreview() {
        const existingLayout = this.element.querySelector('.film-form-layout');
        if (existingLayout) {
            this.savedPreviewImage = existingLayout.querySelector('.film-saved-image-preview img');
            this.savedPreviewMessage = existingLayout.querySelector('.film-saved-image-preview [data-preview-message]');
            this.previewImage = existingLayout.querySelector('.film-selected-image-preview img');
            this.previewMessage = existingLayout.querySelector('.film-selected-image-preview [data-preview-message]');
            return;
        }

        const layout = document.createElement('div');
        layout.className = 'film-form-layout';

        const fields = document.createElement('div');
        fields.className = 'film-form-fields';

        const previews = document.createElement('aside');
        previews.className = 'film-form-previews';

        while (this.element.firstChild) {
            fields.append(this.element.firstChild);
        }

        const savedPreview = this.createPreviewCard(
            'Affiche déjà enregistrée',
            'Impossible de charger cette affiche.',
            'Affiche actuellement enregistrée'
        );
        savedPreview.card.classList.add('film-saved-image-preview');
        savedPreview.card.classList.toggle('d-none', '' === this.savedImageUrl);
        this.savedPreviewImage = savedPreview.image;
        this.savedPreviewMessage = savedPreview.message;

        const selectedPreview = this.createPreviewCard(
            'Aperçu de l’affiche sélectionnée',
            'Sélectionnez un film pour afficher son affiche.',
            'Affiche du film sélectionné'
        );
        selectedPreview.card.classList.add('film-selected-image-preview');
        this.previewImage = selectedPreview.image;
        this.previewMessage = selectedPreview.message;

        previews.append(savedPreview.card, selectedPreview.card);
        layout.append(fields, previews);
        this.element.append(layout);
    }

    createPreviewCard(title, message, imageAlt) {
        const card = document.createElement('div');
        card.className = 'film-image-preview-card border rounded p-3';

        const heading = document.createElement('h3');
        heading.className = 'fs-6';
        heading.textContent = title;

        const previewMessage = document.createElement('p');
        previewMessage.className = 'text-muted mb-0';
        previewMessage.dataset.previewMessage = '';
        previewMessage.textContent = message;

        const image = document.createElement('img');
        image.className = 'img-fluid rounded d-none';
        image.alt = imageAlt;
        image.addEventListener('error', () => {
            image.classList.add('d-none');
            previewMessage.textContent = 'Impossible de charger cette affiche.';
            previewMessage.classList.remove('d-none');
        });

        card.append(heading, previewMessage, image);

        return { card, image, message: previewMessage };
    }

    updateSavedImagePreview() {
        if (!this.savedImageUrl) {
            return;
        }

        this.savedPreviewImage.src = this.savedImageUrl;
        this.savedPreviewImage.classList.remove('d-none');
        this.savedPreviewMessage.classList.add('d-none');
    }

    updateImagePreview() {
        const imageUrl = this.imageFieldTarget.value.trim();
        if (!imageUrl) {
            this.previewImage.removeAttribute('src');
            this.previewImage.classList.add('d-none');
            this.previewMessage.classList.remove('d-none');
            return;
        }

        this.previewImage.src = imageUrl;
        this.previewImage.classList.remove('d-none');
        this.previewMessage.classList.add('d-none');
    }

    get resultsSelectElement() {
        return this.hasResultsSelectTarget
            ? this.resultsSelectTarget
            : this.element.querySelector('select[name$="[api_results]"]');
    }

    // Étape 2 : L'admin tape un nom -> Requête API après un court délai (Debounce)
    search() {
        const query = this.searchInputTarget.value;
        if (query.length < 3) return; // On attend au moins 3 caractères

        clearTimeout(this.timeout);
        const resultsSelect = this.resultsSelectElement;
        if (!resultsSelect) {
            throw new Error('Le champ des résultats de recherche est introuvable.');
        }
        resultsSelect.disabled = false;
        this.timeout = setTimeout(() => {
            fetch(`/admin/api/film/search?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    this.filmsData = data;
                    this.updateSelectOptions(data);
                });
        }, 400); // Évite de spammer l'API à chaque lettre tapée
    }

    // Met à jour la liste déroulante avec les titres trouvés
    updateSelectOptions(films) {
        const resultsSelect = this.resultsSelectElement;
        if (!resultsSelect) {
            throw new Error('Le champ des résultats de recherche est introuvable.');
        }
        resultsSelect.innerHTML = '<option value="">-- Sélectionnez un film --</option>';

        films.forEach((movie, index) => {
            const option = document.createElement('option');
            option.value = index; // On stocke l'index du tableau pour retrouver l'objet complet après
            option.text = `${movie.title} (${movie.release_date ? movie.release_date.split('-')[0] : 'N/A'})`;
            resultsSelect.appendChild(option);
        });
    }

    // Étape 3 : L'admin sélectionne un film -> Remplissage automatique des inputs
    selectMovie() {
        const resultsSelect = this.resultsSelectElement;
        if (!resultsSelect) {
            throw new Error('Le champ des résultats de recherche est introuvable.');
        }
        const selectedIndex = resultsSelect.value;
        if (selectedIndex === "") return;

        const movie = this.filmsData[selectedIndex];
        resultsSelect.disabled = true;

        // Remplissage des champs cibles configurés dans EasyAdmin
        if (this.hasTitleFieldTarget) {
            this.titleFieldTarget.value = movie.title || '';
        }
        if (this.hasSynopsisFieldTarget) {
            this.synopsisFieldTarget.value = movie.overview || '';
        }
        if (this.hasReleaseDateFieldTarget && movie.release_date) {
            this.releaseDateFieldTarget.value = movie.release_date;
        }
        if (this.hasImageFieldTarget) {
            this.imageFieldTarget.value = movie.poster_path
                ? `https://image.tmdb.org/t/p/w500${movie.poster_path}`
                : '';
            this.updateImagePreview();
        }
    }
}