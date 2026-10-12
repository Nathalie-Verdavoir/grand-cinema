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

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeHomeCarousels);
} else {
    initializeHomeCarousels();
}
