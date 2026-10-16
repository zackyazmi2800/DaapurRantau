document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    if (!input) return;
    const label = button.textContent.trim().replace(/^Tampilkan /, '');
    button.hidden = false;
    button.addEventListener('click', () => {
        const visible = input.type === 'password';
        input.type = visible ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(visible));
        button.textContent = `${visible ? 'Sembunyikan' : 'Tampilkan'} ${label}`;
    });
});

document.querySelectorAll('[data-password-rules]').forEach((form) => {
    const password = form.elements.namedItem('password');
    const confirmation = form.elements.namedItem('password_confirmation');
    const feedback = form.querySelector('[data-password-feedback]');
    const byteLimit = Number(form.dataset.passwordByteLimit);
    const render = () => {
        const value = password.value;
        const length = [...value].length;
        const rules = [
            [length >= 8, 'Minimal 8 karakter', 'Password harus terdiri dari minimal 8 karakter.'],
            [length <= 64, 'Maksimal 64 karakter', 'Password maksimal 64 karakter.'],
            [/\S/u.test(value), 'Tidak hanya spasi', 'Password tidak boleh hanya berisi spasi.'],
            [value === confirmation.value && confirmation.value !== '', 'Konfirmasi sesuai', 'Konfirmasi password tidak sesuai.'],
        ];
        if (byteLimit && new TextEncoder().encode(value).length > byteLimit) {
            rules.push([false, '', 'Password maksimal 72 byte. Kurangi panjang atau karakter multibyte.']);
        }
        feedback.replaceChildren();
        rules.forEach(([valid, label, message]) => {
            const line = document.createElement('span');
            line.className = valid ? 'block text-primary' : 'block text-red-800';
            line.textContent = valid ? `✓ ${label}` : `○ ${message}`;
            feedback.append(line);
        });
        return rules.every(([valid]) => valid);
    };
    [password, confirmation].forEach((input) => {
        input.addEventListener('input', () => {
            // Replace stale server password errors with the current inline feedback.
            const errors = document.querySelector('#auth-errors');
            errors?.querySelectorAll('[data-error-field]').forEach((item) => {
                if (['password', 'password_confirmation'].includes(item.dataset.errorField)) item.remove();
            });
            if (errors && !errors.querySelector('[data-error-field]')) errors.hidden = true;
            [password, confirmation].forEach((field) => {
                field.removeAttribute('aria-invalid');
                field.setAttribute('aria-describedby', 'password-feedback');
            });
            render();
        });
    });
    feedback.id = 'password-feedback';
    form.addEventListener('submit', (event) => {
        if (!render()) event.preventDefault();
    });
});
