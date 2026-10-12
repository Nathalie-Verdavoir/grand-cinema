const flashMessageDuration = 3000;

const dismissFlashMessage = (message) => {
    if (message.dataset.dismissing === 'true') {
        return;
    }

    message.dataset.dismissing = 'true';
    message.classList.add('is-dismissing');
    window.setTimeout(() => message.remove(), 180);
};

const initializeFlashMessage = (message) => {
    message.querySelector('[data-flash-dismiss]')?.addEventListener('click', () => {
        dismissFlashMessage(message);
    });
    window.setTimeout(() => dismissFlashMessage(message), flashMessageDuration);
};

const initializeFlashMessages = () => {
    document.querySelectorAll('#flash-messages .flash-message').forEach(initializeFlashMessage);
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeFlashMessages, { once: true });
} else {
    initializeFlashMessages();
}

document.addEventListener('flash:show', (event) => {
    const { message, type } = event.detail || {};
    const flashContainer = document.getElementById('flash-messages');
    if (!flashContainer || typeof message !== 'string' || !message.trim() || !['success', 'danger'].includes(type)) {
        return;
    }

    const flashMessage = document.createElement('div');
    flashMessage.classList.add('alert', `alert-${type}`, 'flash-message');
    flashMessage.setAttribute('role', type === 'danger' ? 'alert' : 'status');

    const text = document.createElement('span');
    text.className = 'flash-message-text';
    text.textContent = message;

    const dismissButton = document.createElement('button');
    dismissButton.className = 'flash-message-dismiss';
    dismissButton.type = 'button';
    dismissButton.dataset.flashDismiss = '';
    dismissButton.setAttribute('aria-label', 'Fermer la notification');
    dismissButton.textContent = '×';

    flashMessage.append(text, dismissButton);
    flashContainer.append(flashMessage);
    initializeFlashMessage(flashMessage);
});
