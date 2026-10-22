import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        filmsUrl: String,
        screeningsUrl: String,
        saleDetailsUrl: String,
    };

    static targets = [
        'fromDate',
        'toDate',
        'fromValue',
        'toValue',
        'film',
        'screening',
        'selectedFilm',
        'feedback',
        'saleDetails',
        'ticketPreview',
        'ticketFrame',
        'ticketFeedback',
    ];

    ticketPdfUrl = null;

    requestController = null;

    disconnect() {
        this.requestController?.abort();
        this.clearTicketPreview();
    }

    clearTicketPreview() {
        this.ticketFrameTarget.removeAttribute('src');
        this.ticketPreviewTarget.hidden = true;
        this.ticketFeedbackTarget.textContent = '';
        if (this.ticketPdfUrl) {
            URL.revokeObjectURL(this.ticketPdfUrl);
            this.ticketPdfUrl = null;
        }
    }

    async loadFilms() {
        const from = this.fromDateTarget.value;
        const to = this.toDateTarget.value;
        const selectedFilmId = this.filmTarget.value;
        this.fromValueTarget.value = from;
        this.toValueTarget.value = to;
        this.filmTarget.disabled = true;
        this.filmTarget.replaceChildren(new Option('Chargement des films…', ''));
        this.selectedFilmTarget.value = '';
        this.screeningTarget.disabled = true;
        this.screeningTarget.replaceChildren(new Option('Choisissez d’abord un film', ''));
        this.feedbackTarget.textContent = '';
        this.saleDetailsTarget.replaceChildren();
        this.saleDetailsTarget.hidden = true;
        this.clearTicketPreview();

        this.requestController?.abort();
        const controller = new AbortController();
        this.requestController = controller;
        const url = new URL(this.filmsUrlValue, window.location.origin);
        url.searchParams.set('from', from);
        url.searchParams.set('to', to);

        try {
            const response = await fetch(url, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error(`Chargement des films impossible (${response.status}).`);
            }

            const films = await response.json();
            if (!Array.isArray(films)) {
                throw new Error('Réponse invalide lors du chargement des films.');
            }

            const options = [new Option(
                films.length ? 'Sélectionner un film' : 'Aucun film pour cette période',
                '',
            )];
            films.forEach((film) => {
                if (!Number.isInteger(film.id) || typeof film.title !== 'string') {
                    throw new Error('Réponse invalide lors du chargement des films.');
                }
                options.push(new Option(film.title, String(film.id)));
            });

            this.filmTarget.replaceChildren(...options);
            this.filmTarget.disabled = films.length === 0;
            if (films.some((film) => String(film.id) === selectedFilmId)) {
                this.filmTarget.value = selectedFilmId;
                this.selectedFilmTarget.value = selectedFilmId;
                await this.loadScreenings();
            }
        } catch {
            if (controller.signal.aborted) {
                return;
            }

            this.filmTarget.replaceChildren(new Option('Films indisponibles', ''));
            this.feedbackTarget.textContent = 'Impossible de charger les films pour cette période. Vérifiez les dates et réessayez.';
        }
    }

    async loadScreenings() {
        const filmId = this.filmTarget.value;
        this.selectedFilmTarget.value = filmId;
        this.screeningTarget.disabled = true;
        this.screeningTarget.replaceChildren(new Option(
            filmId ? 'Chargement des séances…' : 'Choisissez d’abord un film',
            '',
        ));
        this.feedbackTarget.textContent = '';
        this.saleDetailsTarget.replaceChildren();
        this.saleDetailsTarget.hidden = true;
        this.clearTicketPreview();

        this.requestController?.abort();
        if (!filmId) {
            return;
        }

        const controller = new AbortController();
        this.requestController = controller;
        const url = new URL(this.screeningsUrlValue, window.location.origin);
        url.searchParams.set('film', filmId);
        url.searchParams.set('from', this.fromDateTarget.value);
        url.searchParams.set('to', this.toDateTarget.value);

        try {
            const response = await fetch(url, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error(`Chargement des séances impossible (${response.status}).`);
            }

            const screenings = await response.json();
            if (!Array.isArray(screenings)) {
                throw new Error('Réponse invalide lors du chargement des séances.');
            }

            const options = [new Option(
                screenings.length ? 'Sélectionner une séance' : 'Aucune séance disponible pour ce film',
                '',
            )];
            screenings.forEach((screening) => {
                if (!Number.isInteger(screening.id) || typeof screening.label !== 'string') {
                    throw new Error('Réponse invalide lors du chargement des séances.');
                }
                options.push(new Option(screening.label, String(screening.id)));
            });

            this.screeningTarget.replaceChildren(...options);
            this.screeningTarget.disabled = screenings.length === 0;
        } catch {
            if (controller.signal.aborted) {
                return;
            }

            this.screeningTarget.replaceChildren(new Option('Séances indisponibles', ''));
            this.feedbackTarget.textContent = 'Impossible de charger les séances. Réessayez en choisissant à nouveau le film.';
        }
    }

    async loadSaleDetails() {
        const filmId = this.selectedFilmTarget.value;
        const screeningId = this.screeningTarget.value;
        this.clearTicketPreview();
        this.saleDetailsTarget.replaceChildren();
        this.saleDetailsTarget.hidden = true;
        this.feedbackTarget.textContent = '';

        this.requestController?.abort();
        if (!filmId || !screeningId) {
            return;
        }

        const controller = new AbortController();
        this.requestController = controller;
        const url = new URL(this.saleDetailsUrlValue, window.location.origin);
        url.searchParams.set('film', filmId);
        url.searchParams.set('seance', screeningId);
        this.feedbackTarget.textContent = 'Chargement des tarifs…';

        try {
            const response = await fetch(url, {
                signal: controller.signal,
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) {
                throw new Error(`Chargement des tarifs impossible (${response.status}).`);
            }

            const result = await response.json();
            if (typeof result.html !== 'string') {
                throw new Error('Réponse invalide lors du chargement des tarifs.');
            }
            if (controller.signal.aborted) {
                return;
            }

            this.saleDetailsTarget.innerHTML = result.html;
            this.saleDetailsTarget.hidden = false;
            this.feedbackTarget.textContent = '';
        } catch {
            if (controller.signal.aborted) {
                return;
            }

            this.feedbackTarget.textContent = 'Impossible de charger les tarifs. Veuillez sélectionner à nouveau la séance.';
        }
    }

    async sell(event) {
        const form = event.currentTarget;
        event.preventDefault();
        this.requestController?.abort();
        this.requestController = null;
        this.clearTicketPreview();
        this.ticketPreviewTarget.hidden = false;
        this.ticketFeedbackTarget.textContent = 'Enregistrement de la vente et préparation des billets…';

        const submitButton = form.querySelector('button[type="submit"]');
        if (submitButton) {
            submitButton.disabled = true;
        }
        this.filmTarget.disabled = true;
        this.screeningTarget.disabled = true;

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    Accept: 'application/pdf, application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                const contentType = response.headers.get('content-type') || '';
                let message = 'La vente n’a pas pu être finalisée.';
                if (contentType.includes('application/json')) {
                    const result = await response.json();
                    if (typeof result.message === 'string') {
                        message = result.message;
                    }
                }
                throw new Error(message);
            }

            if (!(response.headers.get('content-type') || '').includes('application/pdf')) {
                throw new Error('La vente a été enregistrée, mais le PDF des billets n’a pas pu être chargé.');
            }

            const pdf = await response.blob();
            this.ticketPdfUrl = URL.createObjectURL(pdf);
            this.ticketFrameTarget.src = this.ticketPdfUrl;
            this.ticketFeedbackTarget.textContent = 'Vente enregistrée. Les billets sont affichés ci-dessous.';
        } catch (error) {
            this.ticketFeedbackTarget.textContent = error instanceof Error
                ? error.message
                : 'Une erreur est survenue pendant la vente.';
            if (submitButton) {
                submitButton.disabled = false;
            }
        } finally {
            this.filmTarget.disabled = false;
            this.screeningTarget.disabled = !this.screeningTarget.value;
        }
    }
}
