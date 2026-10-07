document.querySelectorAll('[data-staging-pull-form]').forEach((form) => {
    const button = form.querySelector('[data-staging-pull-submit]');
    const status = form.querySelector('[data-staging-pull-status]');

    if (!button || !status) {
        return;
    }

    const initialLabel = button.textContent;
    const initiallyDisabled = button.disabled;
    let submitting = false;

    form.addEventListener('submit', (event) => {
        if (submitting) {
            event.preventDefault();
            return;
        }

        if (event.defaultPrevented) {
            return;
        }

        submitting = true;
        form.setAttribute('aria-busy', 'true');
        button.disabled = true;
        button.textContent = button.dataset.loadingLabel;
        status.textContent = 'Sedang membaca data sumber dan menyimpan salinan. Tunggu hingga hasil ditampilkan.';
    });

    window.addEventListener('pageshow', () => {
        submitting = false;
        form.removeAttribute('aria-busy');
        button.disabled = initiallyDisabled;
        button.textContent = initialLabel;
        status.textContent = '';
    });
});
