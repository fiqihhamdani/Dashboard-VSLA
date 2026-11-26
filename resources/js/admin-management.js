const isDriveFile = (value) => {
    try {
        const url = new URL(value);
        if (url.protocol !== 'https:' || url.hostname !== 'drive.google.com' || url.username || url.password || url.port || value.match(/^https:\/\/([^/]+)\//)?.[1]?.toLowerCase() !== 'drive.google.com') return false;
        return /^\/file\/d\/[A-Za-z0-9_-]+(?:\/(?:view|preview|edit))?\/?$/.test(url.pathname)
            || (['/open', '/uc'].includes(url.pathname) && /^[A-Za-z0-9_-]+$/.test(url.searchParams.get('id') ?? ''));
    } catch { return false; }
};

document.querySelectorAll('[data-drive-url]').forEach((input) => {
    const validate = () => input.setCustomValidity(!input.value || isDriveFile(input.value) ? '' : 'Masukkan URL file Google Drive yang valid dengan HTTPS.');
    input.addEventListener('input', validate);
    validate();
});

document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

document.querySelectorAll('form[method="post"]:not([data-transaction-form])').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        const button = event.submitter ?? form.querySelector('button[type="submit"], button:not([type])');
        if (button) {
            button.disabled = true;
            button.textContent = 'Memproses…';
        }
        form.setAttribute('aria-busy', 'true');
    });
});
