const page = document.querySelector('[data-cooking-page]');
if (page) {
    const guide = page.querySelector('[data-cooking-guide]');
    const required = [...page.querySelectorAll('[data-stock-item][data-required="true"], [data-cooking-ingredient][data-required="true"]')];
    const steps = [...guide.querySelectorAll('[data-cooking-step]')];
    const previous = guide.querySelector('[data-cooking-previous]');
    const next = guide.querySelector('[data-cooking-next]');
    let index = 0;
    let finished = false;
    const ready = () => required.length > 0 && required.every(item => item.checked);
    const render = (focus = false) => {
        const missing = required.filter(item => !item.checked);
        const available = required.length - missing.length;
        guide.querySelector('[data-preparation-count]').textContent = `${available} dari ${required.length} bahan wajib tersedia`;
        const preparation = guide.querySelector('[data-preparation-progress]');
        preparation.max = required.length || 1;
        preparation.value = available;
        guide.querySelector('[data-preparation-missing]').replaceChildren(...missing.map(item => {
            const li = document.createElement('li');
            li.textContent = item.dataset.name;
            return li;
        }));
        guide.querySelector('[data-cooking-locked]').hidden = ready();
        guide.querySelector('[data-cooking-unlocked]').hidden = !ready();
        guide.querySelector('[data-cooking-missing]').textContent = required.length
            ? `Masih ada ${missing.length} bahan yang belum tersedia.`
            : 'Daftar bahan wajib belum tersedia untuk diperiksa.';
        if (!steps.length) return;
        steps.forEach((step, position) => { step.hidden = position !== index; });
        guide.querySelector('[data-cooking-active]').hidden = finished;
        guide.querySelector('[data-cooking-finished]').hidden = !finished;
        guide.querySelector('[data-cooking-counter]').textContent = `Langkah ${index + 1} dari ${steps.length}`;
        guide.querySelector('[data-cooking-progress]').value = finished ? steps.length : index + 1;
        previous.disabled = index === 0;
        next.textContent = index === steps.length - 1 ? 'Selesai Memasak' : 'Langkah Selanjutnya';
        if (focus && ready()) (finished ? guide.querySelector('[data-cooking-finished] h3') : steps[index].querySelector('h3')).focus();
    };
    previous?.addEventListener('click', () => {
        if (!ready() || index === 0) return;
        index--; render(true);
    });
    next?.addEventListener('click', () => {
        if (!ready() || finished) return;
        if (index < steps.length - 1) index++; else finished = true;
        render(true);
    });
    guide.querySelector('[data-cooking-restart]')?.addEventListener('click', () => {
        if (!ready()) return;
        index = 0; finished = false; render(true);
    });
    page.addEventListener('change', event => {
        if (event.target.matches('[data-stock-item], [data-cooking-ingredient]')) render();
    });
    window.addEventListener('pageshow', () => render());
    render();
}
