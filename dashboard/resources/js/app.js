/*
 * Small progressive enhancements. Every action still works as a plain HTML form.
 *
 * <form data-confirm="Message" data-confirm-title="Title" data-confirm-button="Do it" data-confirm-danger>
 *   asks for confirmation in the shared <dialog id="confirm-dialog"> before submitting.
 * <input type="checkbox" data-select-all="group"> toggles every [data-select="group"] checkbox and
 *   enables [data-requires-selection="group"] buttons; [data-selection-count="group"] shows the count.
 */

function confirmSubmit(form, submitter) {
    const dialog = document.getElementById('confirm-dialog');
    if (!dialog || typeof dialog.showModal !== 'function') {
        return window.confirm(form.dataset.confirm);
    }

    const ok = document.getElementById('confirm-ok');
    document.getElementById('confirm-title').textContent = form.dataset.confirmTitle || 'Are you sure?';
    document.getElementById('confirm-message').textContent = form.dataset.confirm;
    ok.textContent = form.dataset.confirmButton || 'Continue';
    ok.className = 'btn ' + ('confirmDanger' in form.dataset ? 'btn-danger' : 'btn-primary');

    const close = () => dialog.close();
    document.getElementById('confirm-cancel').onclick = close;
    ok.onclick = () => {
        close();
        form.dataset.confirmed = 'yes';
        form.requestSubmit(submitter || undefined);
    };
    dialog.showModal();
    ok.focus();

    return false;
}

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.confirm) {
        return;
    }
    // e.g. a "Preview" button in a form whose main button is destructive.
    if (event.submitter && event.submitter.hasAttribute('data-skip-confirm')) {
        return;
    }
    if (form.dataset.confirmed === 'yes') {
        delete form.dataset.confirmed;
        // Deferred so the clicked button's name/value is still included in the submission.
        setTimeout(() => form.querySelectorAll('button[type="submit"]').forEach((button) => (button.disabled = true)), 0);
        return;
    }
    if (!confirmSubmit(form, event.submitter)) {
        event.preventDefault();
    }
});

// Prevent double submits on ordinary forms too (long-running remote calls).
document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form instanceof HTMLFormElement && !form.dataset.confirm && form.method.toLowerCase() === 'post' && !event.defaultPrevented) {
        setTimeout(() => form.querySelectorAll('button[type="submit"]').forEach((button) => (button.disabled = true)), 0);
    }
});

document.querySelectorAll('[data-select-all]').forEach((master) => {
    const group = master.dataset.selectAll;
    const boxes = () => document.querySelectorAll(`[data-select="${group}"]`);
    const refresh = () => {
        const checked = [...boxes()].filter((box) => box.checked).length;
        master.checked = checked > 0 && checked === boxes().length;
        master.indeterminate = checked > 0 && checked < boxes().length;
        document.querySelectorAll(`[data-requires-selection="${group}"]`).forEach((button) => (button.disabled = checked === 0));
        document.querySelectorAll(`[data-selection-count="${group}"]`).forEach((label) => (label.textContent = checked ? `${checked} selected` : ''));
    };
    master.addEventListener('change', () => {
        boxes().forEach((box) => (box.checked = master.checked));
        refresh();
    });
    boxes().forEach((box) => box.addEventListener('change', refresh));
    refresh();
});
