import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['feedback', 'deleteDialog', 'dailyScroll'];

    pendingDeleteUrl = null;

    connect() {
        this.focusDailyScreening();
    }

    focusDailyScreening() {
        if (!this.hasDailyScrollTarget) {
            return;
        }

        const events = [...this.dailyScrollTarget.querySelectorAll('.schedule-event[data-start-timestamp]')]
            .sort((first, second) => Number(first.dataset.startTimestamp) - Number(second.dataset.startTimestamp));
        if (events.length === 0) {
            return;
        }

        const now = Date.now() / 1000;
        let focusedEvent = events[0];
        if (this.element.dataset.isToday === 'true') {
            focusedEvent = events.find((event) => {
                const start = Number(event.dataset.startTimestamp);
                const end = Number(event.dataset.endTimestamp);

                return start <= now && now < end;
            }) || events.find((event) => Number(event.dataset.startTimestamp) >= now) || events[0];
        }

        const eventRect = focusedEvent.getBoundingClientRect();
        const scrollRect = this.dailyScrollTarget.getBoundingClientRect();
        const eventLeft = eventRect.left - scrollRect.left + this.dailyScrollTarget.scrollLeft;
        const left = eventLeft - ((this.dailyScrollTarget.clientWidth - focusedEvent.offsetWidth) / 2);
        this.dailyScrollTarget.scrollLeft = Math.max(0, left);
    }

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

        const isDaily = lane.classList.contains('daily-room-lane');
        const slot = Math.max(0, Math.min(95, Math.floor((isDaily ? event.clientX - lane.getBoundingClientRect().left : event.clientY - lane.getBoundingClientRect().top) / (isDaily ? 36 : 18))));
        if (slot < Number(lane.dataset.blockedSlots)) {
            this.feedbackTarget.textContent = 'Impossible de programmer une séance sur un créneau déjà passé.';
            this.feedbackTarget.classList.add('is-error');
            return;
        }

        const minutes = slot * 15;
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
                    roomId: lane.dataset.roomId || this.element.dataset.roomId,
                    date: lane.dataset.date,
                    time,
                }),
            });
            const result = await response.json();
            if (!response.ok) {
                this.feedbackTarget.textContent = result.error || 'La séance n’a pas pu être créée.';
                this.feedbackTarget.classList.add('is-error');
                return;
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

    async publish(event) {
        event.stopPropagation();
        const url = event.currentTarget.closest('.schedule-event').dataset.publishUrl;
        this.feedbackTarget.textContent = 'Publication de la séance…';
        this.feedbackTarget.classList.remove('is-error');

        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: {'X-CSRF-Token': this.element.dataset.publishCsrfToken},
            });
            const result = await response.json();
            if (!response.ok) {
                this.feedbackTarget.textContent = result.error || 'La séance n’a pas pu être publiée.';
                this.feedbackTarget.classList.add('is-error');
                return;
            }
            window.location.reload();
        } catch (error) {
            this.feedbackTarget.textContent = error.message;
            this.feedbackTarget.classList.add('is-error');
        }
    }

    confirmDelete(event) {
        event.stopPropagation();
        this.pendingDeleteUrl = event.currentTarget.closest('.schedule-event').dataset.deleteUrl;
        this.deleteDialogTarget.hidden = false;
        this.deleteDialogTarget.querySelector('.seance-delete-dialog-panel button').focus();
    }

    closeDeleteDialog(event) {
        if (event && event.currentTarget === this.deleteDialogTarget.querySelector('.seance-delete-dialog-backdrop')) {
            event.stopPropagation();
        }

        this.deleteDialogTarget.hidden = true;
        this.pendingDeleteUrl = null;
    }

    async deleteConfirmed(event) {
        event.stopPropagation();
        if (!this.pendingDeleteUrl) {
            this.closeDeleteDialog();
            return;
        }

        this.deleteDialogTarget.hidden = true;
        this.feedbackTarget.textContent = 'Suppression de la séance…';
        this.feedbackTarget.classList.remove('is-error');
        this.element.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(this.pendingDeleteUrl, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-Token': this.element.dataset.deleteCsrfToken,
                },
            });
            const result = await response.json();
            if (!response.ok) {
                this.feedbackTarget.textContent = result.error || 'La séance n’a pas pu être supprimée.';
                this.feedbackTarget.classList.add('is-error');
                return;
            }

            window.location.reload();
        } catch (error) {
            this.feedbackTarget.textContent = error.message;
            this.feedbackTarget.classList.add('is-error');
        } finally {
            this.pendingDeleteUrl = null;
            this.element.removeAttribute('aria-busy');
        }
    }
}
