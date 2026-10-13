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
import QRCode from 'qrcode';

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
    document.querySelectorAll('[data-ticket-qr]').forEach(async (canvas) => {
        try {
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
    });
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        initializeHomeCarousels();
        initializeReservationSelections();
        initializeReservationCountdowns();
        initializeTicketQrCodes();
    });
} else {
    initializeHomeCarousels();
    initializeReservationSelections();
    initializeReservationCountdowns();
    initializeTicketQrCodes();
}
