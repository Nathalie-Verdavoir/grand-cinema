import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['feedback'];

    startDrag(event) {
        const filmCard = event.currentTarget;
        event.dataTransfer.setData('text/plain', filmCard.dataset.filmId);
        event.dataTransfer.effectAllowed = 'copy';
    }

    endDrag() {
        this.element.querySelectorAll('.schedule-day-lane.is-drop-target').forEach((lane) => {
            lane.classList.remove('is-drop-target');
        });
    }

    allowDrop(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';
        event.currentTarget.classList.add('is-drop-target');
    }

    leaveDrop(event) {
        if (!event.currentTarget.contains(event.relatedTarget)) {
            event.currentTarget.classList.remove('is-drop-target');
        }
    }

    async drop(event) {
        event.preventDefault();
        const lane = event.currentTarget;
        lane.classList.remove('is-drop-target');

        const filmId = event.dataTransfer.getData('text/plain');
        if (!filmId) {
            return;
        }

        const slot = Math.max(0, Math.min(47, Math.floor((event.clientY - lane.getBoundingClientRect().top) / 40)));
        if (slot < Number(lane.dataset.blockedSlots)) {
            this.feedbackTarget.textContent = 'Impossible de programmer une séance sur un créneau déjà passé.';
            this.feedbackTarget.classList.add('is-error');
            return;
        }

        const minutes = slot * 30;
        const time = `${String(Math.floor(minutes / 60)).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
        this.feedbackTarget.textContent = 'Création de la séance…';
        this.element.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(this.element.dataset.createUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.element.dataset.csrfToken,
                },
                body: JSON.stringify({
                    filmId,
                    roomId: this.element.dataset.roomId,
                    date: lane.dataset.date,
                    time,
                }),
            });
            const result = await response.json();
            if (!response.ok) {
                throw new Error(result.error || 'La séance n’a pas pu être créée.');
            }

            this.feedbackTarget.textContent = result.message;
            window.location.reload();
        } catch (error) {
            this.feedbackTarget.textContent = error.message;
            this.feedbackTarget.classList.add('is-error');
        } finally {
            this.element.removeAttribute('aria-busy');
        }
    }
}
