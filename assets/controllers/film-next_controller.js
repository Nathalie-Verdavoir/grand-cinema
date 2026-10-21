import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    async submit(event) {
        const submitButton = event.submitter;
        if ('saveAndNextFilm' !== submitButton?.value) {
            return;
        }

        event.preventDefault();
        submitButton.disabled = true;

        try {
            const formData = new FormData(this.element, submitButton);
            formData.set(submitButton.name, submitButton.value);
            const response = await fetch(this.element.action, {
                method: this.element.method.toUpperCase(),
                body: formData,
                credentials: 'same-origin',
            });
            const html = await response.text();
            const nextDocument = new DOMParser().parseFromString(html, 'text/html');
            const nextContent = nextDocument.querySelector('.content-wrapper');
            const nextForm = nextDocument.querySelector('#edit-Film-form');
            const currentContent = document.querySelector('.content-wrapper');

            if (!response.ok || !nextContent || !nextForm || !currentContent) {
                throw new Error('La fiche suivante n’a pas pu être chargée.');
            }

            currentContent.replaceWith(nextContent);
            document.title = nextDocument.title;
            document.body.id = nextDocument.body.id;
            window.history.pushState({}, '', response.url);
        } catch (error) {
            submitButton.disabled = false;
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger';
            alert.setAttribute('role', 'alert');
            alert.textContent = error instanceof Error
                ? error.message
                : 'Enregistrement impossible. Réessayez.';
            document.querySelector('.content-wrapper')?.prepend(alert);
            console.error(error);
        }
    }
}
