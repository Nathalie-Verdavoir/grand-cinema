import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['feedback', 'deleteDialog', 'updateDialog', 'updateSummary', 'dailyScroll'];

    pendingDeleteUrl = null;
    pendingUpdate = null;
    boundDocumentClick = null;
    fragmentCache = new Map();
    fragmentRequests = new Map();
    refreshController = null;

    connect() {
        this.boundDocumentClick = (event) => {
            const link = event.target.closest('.seance-view-toggle a');
            if (!link) {
                return;
            }

            event.preventDefault();
            this.refreshFromRequest(link.href);
        };
        document.addEventListener('click', this.boundDocumentClick);
        this.focusDailyScreening();
    }

    disconnect() {
        if (this.boundDocumentClick) {
            document.removeEventListener('click', this.boundDocumentClick);
            this.boundDocumentClick = null;
        }
    }

    async submitForm(event) {
        const form = event.target.closest('form');
        if (!form || (form.method.toLowerCase() !== 'post' && !form.classList.contains('seance-room-selector'))) {
            return;
        }

        event.preventDefault();
        const formData = new FormData(form);
        const method = form.method.toUpperCase();
        if (method === 'GET') {
            const query = new URLSearchParams(formData).toString();
            const url = query ? `${form.action}?${query}` : form.action;
            await this.refreshFromRequest(url);
            return;
        }

        await this.refreshFromRequest(form.action, {
            method,
            body: formData,
        });
    }

    async navigate(event) {
        const link = event.target.closest('a');
        if (!link || !this.element.contains(link) || !link.closest('.seance-week-navigation')) {
            return;
        }

        event.preventDefault();
        await this.refreshFromRequest(link.href);
    }

    async refreshFromRequest(url, options = {}) {
        const cacheKey = options.method ? `${options.method}:${url}` : `GET:${url}`;
        if (!options.method && this.fragmentCache.has(cacheKey)) {
            this.replaceFragment(this.fragmentCache.get(cacheKey), url);
            return;
        }
        if (!options.method && this.fragmentRequests.has(cacheKey)) {
            try {
                const html = await this.fragmentRequests.get(cacheKey);
                this.replaceFragment(html, url);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    this.feedbackTarget.textContent = error.message;
                    this.feedbackTarget.classList.add('is-error');
                }
            }
            return;
        }

        if (this.refreshController) {
            this.refreshController.abort();
        }
        const refreshController = new AbortController();
        this.refreshController = refreshController;
        this.element.setAttribute('aria-busy', 'true');
        this.feedbackTarget.textContent = 'Mise à jour du planning…';
        this.feedbackTarget.classList.remove('is-error');
        let fetchPromise = null;
        let responseUrl = url;

        try {
            const requestOptions = {
                ...options,
                signal: refreshController.signal,
            };
            const requestUrl = options.method ? url : this.getFragmentRequestUrl(url);
            fetchPromise = fetch(requestUrl, {
                ...requestOptions,
                headers: {
                    ...(requestOptions.headers || {}),
                    'X-Requested-With': 'XMLHttpRequest',
                },
            }).then(async (response) => {
                responseUrl = response.url;
                const html = await response.text();
                if (!response.ok) {
                    throw new Error('Le planning n’a pas pu être actualisé.');
                }

                return html;
            });
            if (!options.method) {
                this.fragmentRequests.set(cacheKey, fetchPromise);
            }
            const html = await fetchPromise;

            const documentParser = new DOMParser();
            const parsedDocument = documentParser.parseFromString(html, 'text/html');
            const updatedPlanner = parsedDocument.querySelector('.seance-planner');
            if (!updatedPlanner) {
                throw new Error('La réponse du planning est invalide.');
            }

            if (!options.method) {
                this.fragmentCache.set(cacheKey, html);
            } else {
                this.fragmentCache.clear();
            }
            this.replaceFragment(html, options.method ? responseUrl : url);
            if (options.method) {
                const flashes = [...parsedDocument.querySelectorAll('#flash-messages .alert')]
                    .map((alert) => alert.textContent.replace(/\s+/g, ' ').replace(/^×\s*/, '').trim())
                    .filter(Boolean);
                if (flashes.length > 0) {
                    this.feedbackTarget.textContent = flashes.join(' ');
                    this.feedbackTarget.classList.toggle('is-error', Boolean(parsedDocument.querySelector('#flash-messages .alert-danger, #flash-messages .alert-warning')));
                }
            }
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            this.feedbackTarget.textContent = error.message;
            this.feedbackTarget.classList.add('is-error');
        } finally {
            this.element.removeAttribute('aria-busy');
            if (this.fragmentRequests.get(cacheKey) === fetchPromise) {
                this.fragmentRequests.delete(cacheKey);
            }
            if (this.refreshController === refreshController) {
                this.refreshController = null;
            }
        }
    }

    getFragmentRequestUrl(url) {
        const pageUrl = new URL(url, window.location.href);
        const fragmentUrl = new URL(this.element.dataset.fragmentUrl, window.location.href);
        fragmentUrl.search = pageUrl.search;

        return fragmentUrl.href;
    }

    replaceFragment(html, url) {
        const parsedDocument = new DOMParser().parseFromString(html, 'text/html');
        const updatedPlanner = parsedDocument.querySelector('.seance-planner');
        if (!updatedPlanner) {
            this.feedbackTarget.textContent = 'La réponse du planning est invalide.';
            this.feedbackTarget.classList.add('is-error');
            return;
        }

        const pageUrl = new URL(url, window.location.href);
        window.history.replaceState({}, '', `${pageUrl.pathname}${pageUrl.search}${pageUrl.hash}`);
        this.element.innerHTML = updatedPlanner.innerHTML;
            ['isToday', 'roomId'].forEach((attribute) => {
                if (updatedPlanner.dataset[attribute] !== undefined) {
                    this.element.dataset[attribute] = updatedPlanner.dataset[attribute];
                }
            });

            const currentToggle = document.querySelector('.seance-view-toggle');
            const updatedToggle = parsedDocument.querySelector('.seance-view-toggle');
            if (currentToggle && updatedToggle) {
                currentToggle.replaceWith(updatedToggle);
            }

            this.feedbackTarget.textContent = '';
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
        const source = event.currentTarget;
        const seanceId = source.getAttribute('data-seance-id');
        if (/^\d+$/.test(seanceId || '')) {
            event.dataTransfer.setData('application/x-seance-id', seanceId);
            event.dataTransfer.setData('application/x-seance-url', source.dataset.updateUrl);
            event.dataTransfer.setData('text/plain', `seance:${seanceId}`);
            event.dataTransfer.effectAllowed = 'move';
            return;
        }

        const filmId = source.getAttribute('data-film-id');
        if (!/^\d+$/.test(filmId || '')) {
            event.preventDefault();
            return;
        }

        event.dataTransfer.setData('text/plain', filmId);
        event.dataTransfer.effectAllowed = 'copy';
    }

    endDrag() {
        this.element.querySelectorAll('.schedule-day-lane.is-drop-target').forEach((lane) => {
            lane.classList.remove('is-drop-target');
        });
    }

    allowDrop(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = event.dataTransfer.effectAllowed === 'move' ? 'move' : 'copy';
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
        const customSeanceId = event.dataTransfer.getData('application/x-seance-id');
        const plainSeanceMatch = filmId.match(/^seance:(\d+)$/);
        const seanceId = /^\d+$/.test(customSeanceId) ? customSeanceId : plainSeanceMatch?.[1];
        const isMoving = /^\d+$/.test(seanceId || '');
        if (!isMoving && !/^\d+$/.test(filmId)) {
            this.feedbackTarget.textContent = 'Le film sélectionné est invalide. Rechargez la page puis réessayez.';
            this.feedbackTarget.classList.add('is-error');
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
        const updateUrl = event.dataTransfer.getData('application/x-seance-url') || `/admin/api/seances/${seanceId}`;
        const url = isMoving ? updateUrl : this.element.dataset.createUrl;
        const updateData = {
            url,
            roomId: lane.dataset.roomId || this.element.dataset.roomId,
            date: lane.dataset.date,
            time,
        };
        if (isMoving) {
            this.pendingUpdate = updateData;
            const [year, month, day] = updateData.date.split('-');
            this.updateSummaryTarget.textContent = `La séance sera déplacée au ${day}/${month}/${year} à ${updateData.time}.`;
            this.updateDialogTarget.hidden = false;
            this.updateDialogTarget.querySelector('.seance-update-dialog-panel button.btn-primary').focus();
            return;
        }

        this.feedbackTarget.textContent = isMoving ? 'Déplacement de la séance…' : 'Création de la séance…';
        this.element.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(updateData.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': isMoving ? this.element.dataset.updateCsrfToken : this.element.dataset.csrfToken,
                },
                body: JSON.stringify({
                    ...(isMoving ? {} : {filmId}),
                    roomId: updateData.roomId,
                    date: updateData.date,
                    time: updateData.time,
                }),
            });
            const result = await response.json();
            if (!response.ok) {
                this.feedbackTarget.textContent = result.error || 'La séance n’a pas pu être créée.';
                this.feedbackTarget.classList.add('is-error');
                return;
            }

            this.feedbackTarget.textContent = result.message;
            this.fragmentCache.clear();
            await this.refreshFromRequest(window.location.href);
        } catch (error) {
            this.feedbackTarget.textContent = error.message;
            this.feedbackTarget.classList.add('is-error');
        } finally {
            this.element.removeAttribute('aria-busy');
        }
    }

    closeUpdateDialog(event) {
        if (event && event.currentTarget === this.updateDialogTarget.querySelector('.seance-update-dialog-backdrop')) {
            event.stopPropagation();
        }

        this.updateDialogTarget.hidden = true;
        this.pendingUpdate = null;
    }

    closeDialogs() {
        this.closeDeleteDialog();
        this.closeUpdateDialog();
    }

    async updateConfirmed() {
        if (!this.pendingUpdate) {
            this.closeUpdateDialog();
            return;
        }

        const update = this.pendingUpdate;
        this.closeUpdateDialog();
        this.feedbackTarget.textContent = 'Déplacement de la séance…';
        this.feedbackTarget.classList.remove('is-error');
        this.element.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(update.url, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.element.dataset.updateCsrfToken,
                },
                body: JSON.stringify({
                    roomId: update.roomId,
                    date: update.date,
                    time: update.time,
                }),
            });
            const result = await response.json();
            if (!response.ok) {
                this.feedbackTarget.textContent = result.error || 'La séance n’a pas pu être modifiée.';
                this.feedbackTarget.classList.add('is-error');
                return;
            }

            this.fragmentCache.clear();
            await this.refreshFromRequest(window.location.href);
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
            this.fragmentCache.clear();
            await this.refreshFromRequest(window.location.href);
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

            this.fragmentCache.clear();
            await this.refreshFromRequest(window.location.href);
        } catch (error) {
            this.feedbackTarget.textContent = error.message;
            this.feedbackTarget.classList.add('is-error');
        } finally {
            this.pendingDeleteUrl = null;
            this.element.removeAttribute('aria-busy');
        }
    }
}
