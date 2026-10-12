const form = document.querySelector('[data-ingredient-checklist]');
if (form) {
    const items = [...form.querySelectorAll('[data-stock-item]')];
    const action = form.querySelector('[data-find-available]');
    const feedback = form.querySelector('[data-save-status]');
    const loading = form.querySelector('[data-alternative-loading]');
    let saving = Promise.resolve();
    let submitting = false;
    let revision = 0;
    const confirmed = () => [...new FormData(form).getAll('confirmed[]')];
    const update = () => {
        const missing = items.filter(item => item.dataset.required === 'true' && !item.checked);
        const count = confirmed().length;
        form.querySelector('[data-stock-count]').textContent = count;
        form.querySelector('[data-missing-count]').textContent = missing.length;
        form.querySelector('[data-stock-status]').textContent = missing.length
            ? `Kamu masih membutuhkan ${missing.length} bahan tambahan.`
            : 'Semua bahan sudah tersedia. Yuk, mulai masak!';
        const list = form.querySelector('[data-missing-list]');
        list.replaceChildren(...missing.map(item => {
            const li = document.createElement('li');
            li.textContent = item.dataset.name;
            return li;
        }));
        form.querySelector('[data-missing-section]').hidden = !missing.length;
        items.forEach(item => {
            const label = item.closest('label').querySelector('[data-stock-label]');
            label.textContent = (item.checked ? 'Tersedia' : 'Belum tersedia') + (item.dataset.optional === 'true' ? ' · Opsional' : '');
            label.classList.toggle('text-primary', item.checked);
            label.classList.toggle('text-ink', !item.checked);
        });
        action.disabled = submitting || !count || !missing.length;
    };
    form.addEventListener('change', event => {
        if (!event.target.matches('[data-stock-item]')) return;
        update();
        const values = confirmed();
        const currentRevision = ++revision;
        feedback.textContent = 'Menyimpan ketersediaan bahan...';
        // Serialize writes so a slower earlier response cannot overwrite the newest state.
        saving = saving.then(async () => {
            const response = await fetch(form.dataset.saveUrl, {
                method: 'PATCH', credentials: 'same-origin', keepalive: true,
                headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value},
                body: JSON.stringify({confirmed: values}),
            });
            if (!response.ok) throw new Error('save_failed');
            if (currentRevision === revision) feedback.textContent = 'Ketersediaan bahan tersimpan.';
        }).catch(() => {
            if (currentRevision === revision) feedback.textContent = 'Perubahan belum tersimpan. Coba centang kembali. Saat mencari menu, pilihan saat ini tetap dikirim.';
        });
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (action.disabled || submitting) return;
        submitting = true;
        action.disabled = true;
        action.setAttribute('aria-busy', 'true');
        loading.hidden = false;
        items.forEach(item => { item.disabled = true; });
        // Freeze selected IDs; disabled checkboxes are excluded by native form submission.
        const values = items.filter(item => item.checked).map(item => item.value);
        values.forEach(value => {
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'confirmed[]'; input.value = value;
            input.dataset.submittedStock = 'true'; form.append(input);
        });
        saving.finally(() => HTMLFormElement.prototype.submit.call(form));
    });
    window.addEventListener('pageshow', () => {
        submitting = false;
        items.forEach(item => { item.disabled = false; });
        form.querySelectorAll('[data-submitted-stock]').forEach(input => input.remove());
        action.removeAttribute('aria-busy'); loading.hidden = true; update();
    });
    update();
}
