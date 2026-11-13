const menuToggle = document.querySelector('.menu-toggle');
const siteNav = document.querySelector('#site-nav');

if (menuToggle && siteNav) {
    menuToggle.addEventListener('click', () => {
        const isExpanded = menuToggle.getAttribute('aria-expanded') === 'true';
        menuToggle.setAttribute('aria-expanded', String(!isExpanded));
        siteNav.classList.toggle('is-open', !isExpanded);
    });

    siteNav.addEventListener('click', (event) => {
        if (event.target instanceof Element && event.target.closest('a')) {
            menuToggle.setAttribute('aria-expanded', 'false');
            siteNav.classList.remove('is-open');
        }
    });
}

document.querySelectorAll('[data-confirm-vote]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm('Submit your ballot? You will not be able to change your choices.')) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-confirm-publish]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm('Publish the final results? Students will be able to see the vote totals.')) {
            event.preventDefault();
        }
    });
});

document.querySelectorAll('[data-confirm-delete]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = form.getAttribute('data-confirm-delete');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });
});
