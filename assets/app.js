/*
 * Welcome to your app's main JavaScript file!
 *
 * We recommend including the built version of this JavaScript file
 * (and its CSS file) in your base layout (base.html.twig).
 */

// any CSS you import will output into a single css file (app.css in this case)
import './styles/app.css';
import './styles/flash-messages.css';
import './flash-messages.js';

const ticketQrInitialization = new WeakMap();
let qrCodePromise;
let jsPdfPromise;

const loadQRCode = () => {
    if (!qrCodePromise) {
        qrCodePromise = import('qrcode').then(({ default: QRCode }) => QRCode);
    }

    return qrCodePromise;
};

const loadJsPdf = () => {
    if (!jsPdfPromise) {
        jsPdfPromise = import('jspdf').then(({ jsPDF }) => jsPDF);
    }

    return jsPdfPromise;
};

const initializeReservationCancellationModal = () => {
    const modal = document.querySelector('#paid-cancel-reservation-modal');
    if (!modal) {
        return;
    }

    modal.addEventListener('show.bs.modal', (event) => {
        const trigger = event.relatedTarget;
        if (!(trigger instanceof HTMLElement)) {
            return;
        }

        const form = modal.querySelector('[data-cancel-reservation-form]');
        const token = modal.querySelector('[data-cancel-reservation-token]');
        const film = modal.querySelector('[data-cancel-reservation-film]');
        if (!form || !token || !film) {
            return;
        }

        form.action = trigger.dataset.cancelUrl || '';
        token.value = trigger.dataset.csrfToken || '';
        film.textContent = trigger.dataset.reservationTitle || '';
    });
};

const initializeProgrammingWeekNavigation = () => {
    const section = document.querySelector('[data-programming-week]');
    const content = section?.querySelector('[data-programming-week-content]');
    const status = section?.querySelector('[data-programming-week-status]');
    const errorMessage = section?.querySelector('[data-programming-week-error]');
    if (!section || !content || !status || !errorMessage) {
        return;
    }

    let activeRequest = null;
    const loadWeek = async (url, updateHistory = true) => {
        activeRequest?.abort();
        const request = new AbortController();
        activeRequest = request;
        section.setAttribute('aria-busy', 'true');
        status.textContent = 'Chargement des séances…';
        errorMessage.classList.add('d-none');

        try {
            const response = await fetch(url, {
                headers: {
                    Accept: 'text/html',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                signal: request.signal,
            });
            if (!response.ok) {
                errorMessage.textContent = `Impossible de charger les séances (erreur ${response.status}).`;
                errorMessage.classList.remove('d-none');
                status.textContent = '';
                return;
            }

            content.innerHTML = await response.text();
            if (updateHistory) {
                window.history.pushState(null, '', url);
            }
            status.textContent = 'Les séances ont été mises à jour.';
        } catch (error) {
            if (error.name !== 'AbortError') {
                errorMessage.textContent = `Impossible de charger les séances : ${error.message}`;
                errorMessage.classList.remove('d-none');
                status.textContent = '';
            }
        } finally {
            if (activeRequest === request) {
                activeRequest = null;
                section.setAttribute('aria-busy', 'false');
            }
        }
    };

    const loadFormWeek = (form) => {
        const url = new URL(form.action, window.location.href);
        const formData = new FormData(form);
        formData.forEach((value, name) => {
            if (typeof value === 'string') {
                url.searchParams.set(name, value);
            }
        });
        url.hash = 'seances';
        loadWeek(url);
    };

    section.addEventListener('change', (event) => {
        const select = event.target.closest('#audience-week');
        if (select?.form) {
            loadFormWeek(select.form);
        }
    });

    section.addEventListener('submit', (event) => {
        const form = event.target.closest('.audience-week-toolbar form');
        if (!form) {
            return;
        }

        event.preventDefault();
        loadFormWeek(form);
    });

    section.addEventListener('click', (event) => {
        const link = event.target.closest('.audience-week-navigation a[href]');
        if (!link) {
            return;
        }

        event.preventDefault();
        loadWeek(link.href);
    });

    window.addEventListener('popstate', () => loadWeek(window.location.href, false));
};

const initializeHomeCarousels = () => {
    document.querySelectorAll('[data-home-carousel]').forEach((carousel) => {
        const slides = Array.from(carousel.querySelectorAll('.carousel-item'));
        const indicators = Array.from(carousel.querySelectorAll('[data-home-slide-to]'));

        if (slides.length < 2) {
            return;
        }

        let currentIndex = slides.findIndex((slide) => slide.classList.contains('active'));
        let manuallyPaused = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        let temporarilyPaused = false;
        let intervalId;

        const showSlide = (nextIndex) => {
            currentIndex = (nextIndex + slides.length) % slides.length;

            slides.forEach((slide, index) => {
                const isActive = index === currentIndex;
                slide.classList.toggle('active', isActive);
                slide.setAttribute('aria-hidden', String(!isActive));
            });

            indicators.forEach((indicator, index) => {
                const isActive = index === currentIndex;
                indicator.classList.toggle('active', isActive);
                if (isActive) {
                    indicator.setAttribute('aria-current', 'true');
                } else {
                    indicator.removeAttribute('aria-current');
                }
            });
        };

        const stopRotation = () => {
            window.clearInterval(intervalId);
            intervalId = undefined;
        };

        const startRotation = () => {
            if (!manuallyPaused && !temporarilyPaused && intervalId === undefined) {
                intervalId = window.setInterval(() => showSlide(currentIndex + 1), 5000);
            }
        };

        const toggle = carousel.querySelector('[data-carousel-toggle]');
        const updateToggle = () => {
            const label = manuallyPaused ? 'Reprendre le défilement' : 'Mettre en pause le défilement';
            toggle.setAttribute('aria-label', label);
            toggle.setAttribute('title', label);
            toggle.setAttribute('aria-pressed', String(manuallyPaused));
            toggle.querySelector('[data-carousel-pause-icon]').hidden = manuallyPaused;
            toggle.querySelector('[data-carousel-play-icon]').hidden = !manuallyPaused;
        };

        carousel.querySelectorAll('[data-carousel-direction]').forEach((button) => {
            button.addEventListener('click', () => {
                showSlide(currentIndex + Number(button.dataset.carouselDirection));
            });
        });

        indicators.forEach((indicator) => {
            indicator.addEventListener('click', () => {
                showSlide(Number(indicator.dataset.homeSlideTo));
            });
        });

        toggle.addEventListener('click', () => {
            manuallyPaused = !manuallyPaused;
            updateToggle();
            stopRotation();
            startRotation();
        });

        carousel.addEventListener('mouseenter', () => {
            temporarilyPaused = true;
            stopRotation();
        });
        carousel.addEventListener('mouseleave', () => {
            temporarilyPaused = false;
            startRotation();
        });
        carousel.addEventListener('focusin', () => {
            temporarilyPaused = true;
            stopRotation();
        });
        carousel.addEventListener('focusout', (event) => {
            if (!carousel.contains(event.relatedTarget)) {
                temporarilyPaused = false;
                startRotation();
            }
        });

        showSlide(currentIndex);
        updateToggle();
        startRotation();
    });
};

const initializeReservationSelections = () => {
    document.querySelectorAll('[data-reservation-selection]').forEach((selection) => {
        const availableSeats = Number(selection.dataset.availableSeats);
        const tickets = Array.from(selection.querySelectorAll('[data-reservation-ticket]'));
        const summary = selection.querySelector('[data-reservation-total]');
        const submitButton = selection.querySelector('[data-reservation-submit]');
        const currencyFormatter = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });

        const update = (changedTicket = null) => {
            if (changedTicket) {
                const otherSeats = tickets
                    .filter((ticket) => ticket !== changedTicket)
                    .reduce((total, ticket) => total + Number(ticket.value || 0), 0);
                const maximum = Math.max(0, availableSeats - otherSeats);
                changedTicket.max = String(maximum);
                changedTicket.value = String(Math.min(maximum, Math.max(0, Number(changedTicket.value) || 0)));
            }

            const selectedSeats = tickets.reduce((total, ticket) => total + Number(ticket.value || 0), 0);
            tickets.forEach((ticket) => {
                const otherSeats = selectedSeats - Number(ticket.value || 0);
                ticket.max = String(Math.max(0, availableSeats - otherSeats));

                const quantityControl = ticket.closest('.reservation-quantity-control');
                quantityControl.querySelector('[data-reservation-step="-1"]').disabled = Number(ticket.value) <= 0;
                quantityControl.querySelector('[data-reservation-step="1"]').disabled = Number(ticket.value) >= Number(ticket.max);
            });

            const totalInCents = tickets.reduce((total, ticket) => (
                total + Math.round(Number(ticket.dataset.unitPrice) * 100) * Number(ticket.value || 0)
            ), 0);
            const placeLabel = selectedSeats > 1 ? 'places sélectionnées' : 'place sélectionnée';
            summary.textContent = `${selectedSeats} ${placeLabel} · Total : ${currencyFormatter.format(totalInCents / 100)}`;
            submitButton.disabled = selectedSeats === 0;
        };

        tickets.forEach((ticket) => ticket.addEventListener('input', () => update(ticket)));
        selection.querySelectorAll('[data-reservation-step]').forEach((button) => {
            button.addEventListener('click', () => {
                const ticket = button.closest('.reservation-quantity-control').querySelector('[data-reservation-ticket]');
                ticket.value = String(Number(ticket.value) + Number(button.dataset.reservationStep));
                update(ticket);
            });
        });
        selection.addEventListener('submit', () => {
            submitButton.disabled = true;
            submitButton.textContent = 'Enregistrement…';
        });
        update();
    });
};

const initializeReservationCountdowns = () => {
    document.querySelectorAll('[data-reservation-countdown]').forEach((countdown) => {
        const expirationTimestamp = Number(countdown.dataset.expirationTimestamp);
        const time = countdown.querySelector('time');

        if (!Number.isFinite(expirationTimestamp) || !time) {
            return;
        }

        const update = () => {
            const remainingSeconds = Math.max(0, Math.ceil((expirationTimestamp - Date.now()) / 1000));
            const minutes = Math.floor(remainingSeconds / 60);
            const seconds = remainingSeconds % 60;

            if (remainingSeconds === 0) {
                time.textContent = 'Délai écoulé — actualisez la page pour vérifier la réservation.';
                countdown.classList.add('is-expired');
                window.clearInterval(intervalId);
                return;
            }

            time.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        };

        const intervalId = window.setInterval(update, 1000);
        update();
    });
};

const loadImage = (src) => new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve(image);
    image.onerror = reject;
    image.src = src;
});

const drawPosterInQrCode = async (canvas, posterUrl) => {
    try {
        const poster = await loadImage(posterUrl);
        const context = canvas.getContext('2d');
        const size = Math.round(canvas.width * 0.26);
        const border = Math.round(size * 0.08);
        const x = Math.round((canvas.width - size) / 2);
        const y = Math.round((canvas.height - size) / 2);
        const ratio = Math.max(size / poster.naturalWidth, size / poster.naturalHeight);
        const sourceWidth = size / ratio;
        const sourceHeight = size / ratio;

        context.fillStyle = '#fff';
        context.fillRect(x - border, y - border, size + border * 2, size + border * 2);
        context.drawImage(
            poster,
            (poster.naturalWidth - sourceWidth) / 2,
            (poster.naturalHeight - sourceHeight) / 2,
            sourceWidth,
            sourceHeight,
            x,
            y,
            size,
            size,
        );
    } catch (error) {
        console.warn('Affiche indisponible pour le code QR du billet.', error);
    }
};

const initializeTicketQrCodes = () => {
    const canvases = document.querySelectorAll('[data-ticket-qr]');
    if (canvases.length === 0) {
        return;
    }

    canvases.forEach(async (canvas) => {
        const initialization = (async () => {
            try {
                const QRCode = await loadQRCode();
                await QRCode.toCanvas(canvas, canvas.dataset.ticketQr, {
                    width: 240,
                    margin: 2,
                    errorCorrectionLevel: 'H',
                });
                if (canvas.dataset.ticketPoster) {
                    await drawPosterInQrCode(canvas, canvas.dataset.ticketPoster);
                }
                canvas.hidden = false;
            } catch (error) {
                console.error('Impossible de générer le code QR du billet.', error);
                const fallback = document.createElement('span');
                fallback.className = 'reservation-ticket-qr-error';
                fallback.textContent = 'Code QR indisponible. Utilisez la référence affichée.';
                canvas.after(fallback);
            }
        })();
        ticketQrInitialization.set(canvas, initialization);
    });
};

const initializeTicketDownloads = () => {
    const downloadButtons = document.querySelectorAll('[data-download-ticket], [data-download-reservation]');
    const downloadStatus = document.querySelector('[data-download-status]');

    const downloadTickets = async (button) => {
        const selectedTicket = button.closest('.reservation-ticket');
        const tickets = selectedTicket
            ? [selectedTicket]
            : Array.from(document.querySelectorAll('.reservation-ticket'));

        if (tickets.length === 0) {
            return;
        }

        button.disabled = true;
        if (downloadStatus) {
            downloadStatus.textContent = '';
        }

        try {
            const jsPDF = await loadJsPdf();
            const isSingleTicket = Boolean(selectedTicket);
            const layoutPdf = new jsPDF({ unit: 'mm', format: 'a4' });
            const sheetWidth = 210;
            const sheetHeight = 297;
            const columns = 4;
            const ticketsPerPage = 16;
            const printMargin = 8;
            const gap = 2;
            const cardWidth = (sheetWidth - printMargin * 2 - gap * (columns - 1)) / columns;
            const cardHeight = (sheetHeight - printMargin * 2 - gap * (columns - 1)) / columns;
            const titleFontSize = 6.5;
            const titleLineHeight = 2.3;
            const labelFontSize = 4.4;
            const labelLineHeight = 1.8;
            const valueFontSize = 5.2;
            const valueLineHeight = 2.1;
            const qrSize = 27;
            const textWidth = cardWidth - 6.8;
            const pages = [];

            for (const ticket of tickets) {
                const reference = ticket.querySelector('.reservation-ticket-reference span')?.textContent?.trim();
                if (!reference) {
                    throw new Error('La référence du billet est introuvable.');
                }

                const title = ticket.querySelector('h2')?.textContent?.trim() ?? 'Billet de cinéma';
                const details = Array.from(ticket.querySelectorAll('dl > div')).map((row) => {
                    const label = row.querySelector('dt')?.textContent?.trim() ?? '';
                    const value = label === 'Référence du billet'
                        ? reference
                        : row.querySelector('dd')?.textContent?.trim() ?? '';

                    return [label, value];
                });

                const qrCanvas = ticket.querySelector('[data-ticket-qr]');
                const qrInitialization = qrCanvas ? ticketQrInitialization.get(qrCanvas) : null;
                if (qrInitialization) {
                    await qrInitialization;
                }

                let qrCode;
                try {
                    qrCode = qrCanvas && !qrCanvas.hidden
                        ? qrCanvas.toDataURL('image/png')
                        : undefined;
                } catch (error) {
                    console.warn('Le code QR du billet ne peut pas être exporté avec son affiche.', error);
                }
                if (!qrCode) {
                    const QRCode = await loadQRCode();
                    qrCode = await QRCode.toDataURL(reference, {
                        width: 240,
                        margin: 2,
                        errorCorrectionLevel: 'H',
                    });
                }

                layoutPdf.setFont('times', 'normal');
                layoutPdf.setFontSize(titleFontSize);
                const titleLines = layoutPdf.splitTextToSize(title, textWidth);

                let y = 4 + titleLines.length * titleLineHeight + 0.8;
                const rows = details.map(([label, value]) => {
                    layoutPdf.setFont('helvetica', 'bold');
                    layoutPdf.setFontSize(labelFontSize);
                    const labelLines = layoutPdf.splitTextToSize(label, textWidth);
                    layoutPdf.setFont('helvetica', 'normal');
                    layoutPdf.setFontSize(valueFontSize);
                    const valueLines = layoutPdf.splitTextToSize(value, textWidth);
                    const row = { labelLines, valueLines };
                    y += labelLines.length * labelLineHeight
                        + 0.3
                        + valueLines.length * valueLineHeight
                        + 0.5;

                    return row;
                });

                const qrY = y + 0.5;
                pages.push({
                    titleLines,
                    rows,
                    qrCode,
                    qrY,
                });
            }

            const pdf = new jsPDF({ unit: 'mm', format: 'a4' });
            pages.forEach((page, index) => {
                if (index > 0 && index % ticketsPerPage === 0) {
                    pdf.addPage('a4');
                }

                const firstTicketOnPage = Math.floor(index / ticketsPerPage) * ticketsPerPage;
                const row = Math.floor((index - firstTicketOnPage) / columns);
                const column = (index - firstTicketOnPage) % columns;
                const cardX = printMargin + column * (cardWidth + gap);
                const cardY = printMargin + row * (cardHeight + gap);

                pdf.setFillColor(247, 250, 252);
                pdf.roundedRect(cardX + 1.5, cardY + 1.5, cardWidth - 3, cardHeight - 3, 1.5, 1.5, 'F');
                pdf.setFillColor(24, 46, 65);
                pdf.roundedRect(
                    cardX + 1,
                    cardY + 1,
                    1.2,
                    cardHeight - 2,
                    1,
                    1,
                    'F'
                );
                pdf.setDrawColor(54, 89, 125);
                pdf.setLineWidth(0.25);
                pdf.setLineDashPattern([1, 0.8], 0);
                pdf.roundedRect(cardX + 1.5, cardY + 1.5, cardWidth - 3, cardHeight - 3, 1.5, 1.5, 'S');
                pdf.setLineDashPattern([], 0);

                pdf.setTextColor(24, 46, 65);
                pdf.setFont('times', 'normal');
                pdf.setFontSize(titleFontSize);
                const contentX = cardX + 3;
                const contentTop = cardY + 4;
                pdf.text(page.titleLines, contentX, contentTop + 0.8);

                let y = contentTop + page.titleLines.length * titleLineHeight + 0.8;
                page.rows.forEach((row) => {
                    pdf.setTextColor(36, 63, 90);
                    pdf.setFont('helvetica', 'bold');
                    pdf.setFontSize(labelFontSize);
                    pdf.text(row.labelLines, contentX, y);
                    y += row.labelLines.length * labelLineHeight + 0.3;

                    pdf.setTextColor(24, 46, 65);
                    pdf.setFont('helvetica', 'normal');
                    pdf.setFontSize(valueFontSize);
                    pdf.text(row.valueLines, contentX, y);
                    y += row.valueLines.length * valueLineHeight + 0.5;
                });

                const qrX = cardX + (cardWidth - qrSize) / 2;
                const qrBorder = 2;
                pdf.setFillColor(255, 255, 255);
                pdf.setDrawColor(207, 219, 230);
                pdf.setLineWidth(0.3);
                pdf.roundedRect(qrX, cardY + page.qrY, qrSize, qrSize, 1, 1, 'FD');
                pdf.addImage(
                    page.qrCode,
                    'PNG',
                    qrX + qrBorder,
                    cardY + page.qrY + qrBorder,
                    qrSize - qrBorder * 2,
                    qrSize - qrBorder * 2
                );

                pdf.setTextColor(36, 63, 90);
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(4.8);
                pdf.text(
                    'Présentez ce code QR à l’entrée.',
                    contentX,
                    cardY + page.qrY + qrSize + 1.8
                );
            });

            const reference = isSingleTicket
                ? tickets[0].querySelector('.reservation-ticket-reference span')?.textContent?.trim()
                : button.dataset.reservationReference;
            await pdf.save(`${isSingleTicket ? 'billet' : 'billets-reservation'}-${reference}.pdf`, {
                returnPromise: true,
            });
        } catch (error) {
            console.error('Impossible de télécharger le PDF des billets.', error);
            if (downloadStatus) {
                downloadStatus.textContent = 'Le téléchargement des billets a échoué. Veuillez réessayer.';
            }
        } finally {
            button.disabled = false;
        }
    };

    downloadButtons.forEach((button) => {
        button.addEventListener('click', () => downloadTickets(button));
    });

    if (new URLSearchParams(window.location.search).get('download') === 'all') {
        document.querySelector('[data-download-reservation]')?.click();
    }
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initializeProgrammingWeekNavigation();
        initializeHomeCarousels();
        initializeReservationSelections();
        initializeReservationCountdowns();
        initializeReservationCancellationModal();
        initializeTicketQrCodes();
        initializeTicketDownloads();
    });
} else {
    initializeProgrammingWeekNavigation();
    initializeHomeCarousels();
    initializeReservationSelections();
    initializeReservationCountdowns();
    initializeReservationCancellationModal();
    initializeTicketQrCodes();
    initializeTicketDownloads();
}
