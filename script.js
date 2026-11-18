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

document.querySelectorAll('[data-confirm-new-election]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = 'Start a new election? The published results will be archived, but current candidates and ballots will be cleared. Student accounts will be kept.';
        if (!window.confirm(message)) {
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

const studentSearch = document.querySelector('[data-student-search]');
const studentFilter = document.querySelector('[data-student-filter]');
const studentRows = Array.from(document.querySelectorAll('[data-student-row]'));
const studentCount = document.querySelector('[data-student-count]');

if (studentSearch && studentFilter && studentCount) {
    const filterStudentRows = () => {
        const query = studentSearch.value.trim().toLocaleLowerCase();
        const status = studentFilter.value;
        let visibleCount = 0;

        studentRows.forEach((row) => {
            const matchesSearch = row.getAttribute('data-student-search').toLocaleLowerCase().includes(query);
            const hasVoted = row.getAttribute('data-student-voted') === '1';
            const matchesStatus = status === 'all'
                || (status === 'voted' && hasVoted)
                || (status === 'not-voted' && !hasVoted);

            row.hidden = !matchesSearch || !matchesStatus;
            if (!row.hidden) {
                visibleCount += 1;
            }
        });

        studentCount.textContent = `${visibleCount} of ${studentRows.length} students shown`;
    };

    studentSearch.addEventListener('input', filterStudentRows);
    studentFilter.addEventListener('change', filterStudentRows);
    filterStudentRows();
}
