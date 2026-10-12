const form = document.querySelector('#ingredient-form');
if (form) {
    const ingredientLimit = Number(form.dataset.ingredientLimit) || 15;
    const input = form.querySelector('#ingredient-input');
    const chips = form.querySelector('#ingredient-chips');
    const values = form.querySelector('#ingredient-values');
    const feedback = form.querySelector('#ingredient-feedback');
    const search = form.querySelector('#search-recipes');
    const aliases = JSON.parse(form.dataset.ingredientAliases || '{}');
    const normalize = (value) => {
        const name = value.trim().replace(/\s+/gu, ' ').toLocaleLowerCase('id-ID');
        return Object.hasOwn(aliases, name) ? aliases[name] : name;
    };
    const catalogButtons = [...form.querySelectorAll('[data-catalog-ingredient]')];
    const categoryButtons = [...form.querySelectorAll('[data-catalog-category]')];
    let category = '';
    let ingredients = [...new Set([...values.querySelectorAll('input')].map((field) => normalize(field.value)))];
    let submitting = false;

    function render() {
        chips.replaceChildren();
        values.replaceChildren();
        form.querySelector('#ingredient-empty').hidden = ingredients.length > 0;
        form.querySelector('#ingredient-count').textContent = `Bahan dipilih: ${ingredients.length} / ${ingredientLimit}`;
        form.querySelector('#selected-count').textContent = ingredients.length;
        search.disabled = ingredients.length === 0 || submitting;
        catalogButtons.forEach(button => {
            const selected = ingredients.includes(normalize(button.dataset.catalogIngredient));
            button.setAttribute('aria-pressed', String(selected));
            button.querySelector('[data-catalog-selected]').textContent = selected ? ' ✓' : ' +';
        });
        ingredients.forEach((ingredient, index) => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'ingredients[]';
            hidden.value = ingredient;
            values.append(hidden);
            const chip = document.createElement('span');
            chip.className = 'inline-flex max-w-full items-center gap-2 rounded-full bg-sage px-3 py-1.5 text-sm font-medium text-primary';
            const label = document.createElement('span');
            label.textContent = ingredient;
            label.className = 'min-w-0 break-all';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'flex size-7 shrink-0 items-center justify-center rounded-full text-lg hover:bg-primary/15';
            remove.textContent = '×';
            remove.setAttribute('aria-label', `Hapus ${ingredient}`);
            remove.addEventListener('click', () => {
                if (submitting) return;
                ingredients.splice(index, 1);
                render();
                feedback.textContent = `${ingredient} dihapus.`;
                input.focus();
            });
            chip.append(label, remove);
            chips.append(chip);
        });
    }

    function add(value, focusInput = true) {
        if (submitting) return false;
        const batch = value.split(',').map(normalize);
        let error = '';
        if (batch.some((item) => !item)) error = 'Nama bahan tidak boleh kosong. Periksa pemisah koma.';
        else if (batch.some((item) => [...item].length > 60)) error = 'Setiap nama bahan maksimal 60 karakter.';
        else if (batch.some((item) => !/^[\p{L}\p{M}\p{N} .’'()\-]+$/u.test(item))) error = 'Nama bahan mengandung karakter yang tidak diperbolehkan.';
        else if (new Set(batch).size !== batch.length || batch.some((item) => ingredients.includes(item))) error = 'Bahan yang sama tidak boleh dimasukkan dua kali.';
        else if (ingredients.length + batch.length > ingredientLimit) error = `Maksimal ${ingredientLimit} bahan. Hapus bahan atau kurangi daftar yang ditambahkan.`;
        if (error) {
            feedback.textContent = error;
            input.setAttribute('aria-invalid', 'true');
            input.focus();
            return false;
        }
        ingredients.push(...batch);
        input.value = '';
        filterCatalog();
        input.removeAttribute('aria-invalid');
        render();
        feedback.textContent = `${batch.length} bahan ditambahkan.`;
        if (focusInput) input.focus();
        return true;
    }

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.isComposing) {
            event.preventDefault();
            add(input.value);
        }
    });
    form.querySelector('#add-ingredient').addEventListener('click', () => add(input.value));
    catalogButtons.forEach(button => button.addEventListener('click', () => {
        if (submitting) return;
        const name = normalize(button.dataset.catalogIngredient);
        const index = ingredients.indexOf(name);
        if (index >= 0) {
            ingredients.splice(index, 1);
            render();
            feedback.textContent = `${name} dihapus.`;
        } else add(name, false);
    }));
    function filterCatalog() {
        const query = normalize(input.value);
        let count = 0;
        form.querySelectorAll('[data-catalog-group]').forEach(group => {
            let visible = 0;
            group.querySelectorAll('[data-catalog-ingredient]').forEach(button => {
                button.hidden = (category !== '' && category !== group.dataset.catalogGroup)
                    || !normalize(button.dataset.catalogIngredient).includes(query);
                if (!button.hidden) visible++;
            });
            group.hidden = visible === 0;
            count += visible;
        });
        form.querySelector('#catalog-empty').hidden = count > 0;
        form.querySelector('#catalog-status').textContent = `${count} pilihan bahan ditemukan.`;
    }
    input.addEventListener('input', filterCatalog);
    categoryButtons.forEach(button => button.addEventListener('click', () => {
        category = button.dataset.catalogCategory;
        categoryButtons.forEach(item => item.setAttribute('aria-pressed', String(item === button)));
        filterCatalog();
    }));
    filterCatalog();
    form.addEventListener('submit', (event) => {
        if (submitting) { event.preventDefault(); return; }
        if (input.value.trim()) {
            event.preventDefault();
            feedback.textContent = 'Tekan Tambah atau Enter untuk mengonfirmasi bahan yang diketik, atau kosongkan pencarian sebelum mencari resep.';
            input.focus();
            return;
        }
        if (!ingredients.length) {
            event.preventDefault();
            feedback.textContent = 'Masukkan minimal satu bahan makanan.';
            input.focus();
            return;
        }
        feedback.textContent = 'Lagi nyari ide masakan buat kamu...';
        submitting = true;
        search.disabled = true;
        search.setAttribute('aria-busy', 'true');
        search.querySelector('[data-button-label]').textContent = 'Lagi nyari ide masakan buat kamu...';
        search.querySelector('[data-spinner]').hidden = false;
    });
    window.addEventListener('pageshow', () => {
        submitting = false;
        search.removeAttribute('aria-busy');
        search.querySelector('[data-button-label]').textContent = 'Cari Ide Masakan';
        search.querySelector('[data-spinner]').hidden = true;
        render();
    });
    render();
    document.querySelector('#validation-errors')?.focus();
}
