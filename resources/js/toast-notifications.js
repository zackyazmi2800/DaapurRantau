const system = document.querySelector('[data-toast-system]');
if (system) {
    const stack = system.querySelector('[data-toast-stack]');
    const types = {
        success: { duration: 5000, icon: '✓', label: 'Berhasil', style: 'border-primary/25 bg-sage text-primary' },
        info: { duration: 7000, icon: 'i', label: 'Informasi', style: 'border-line bg-white text-ink' },
        warning: { duration: 10000, icon: '!', label: 'Perhatian', style: 'border-accent bg-cream text-ink' },
        error: { duration: 10000, icon: '!', label: 'Gagal', style: 'border-red-200 bg-red-50 text-red-800' },
    };
    const queue = [];
    const active = new Set();
    const seen = new Set();
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const navigationOpen = () => ['profile-toggle', 'menu-toggle'].some((id) =>
        document.getElementById(id)?.getAttribute('aria-expanded') === 'true');

    function sync(entry) {
        clearTimeout(entry.timer);
        if (entry.started !== null) {
            entry.remaining = Math.max(0, entry.remaining - (performance.now() - entry.started));
            entry.started = null;
        }
        if (entry.closing || document.hidden || navigationOpen() || entry.hovered || entry.node.contains(document.activeElement)) return;
        entry.started = performance.now();
        entry.timer = setTimeout(() => dismiss(entry), entry.remaining);
    }

    function dismiss(entry, immediate = false) {
        if (entry.closing) return;
        entry.closing = true;
        clearTimeout(entry.timer);
        if (entry.node.contains(document.activeElement)) {
            const other = [...active].find((item) => item !== entry && !item.closing);
            (other?.close || document.getElementById('konten'))?.focus({ preventScroll: true });
        }
        entry.node.classList.add('toast-leaving');
        const finish = () => {
            entry.node.remove();
            active.delete(entry);
            pump();
        };
        if (immediate || reducedMotion.matches) finish();
        else setTimeout(finish, 180);
    }

    function pump() {
        while (queue.length && active.size < 3) {
            const { content, type } = queue.shift();
            const settings = Object.hasOwn(types, type) ? types[type] : types.info;
            const node = document.createElement('div');
            node.className = `toast-card pointer-events-auto flex items-start gap-3 rounded-2xl border p-4 shadow-soft ${settings.style}`;
            const icon = document.createElement('span');
            icon.className = 'flex size-6 shrink-0 items-center justify-center rounded-full border border-current text-sm font-bold';
            icon.textContent = settings.icon;
            icon.setAttribute('aria-hidden', 'true');
            const body = document.createElement('div');
            body.className = 'min-w-0 flex-1';
            body.setAttribute('role', type === 'error' ? 'alert' : 'status');
            body.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
            body.setAttribute('aria-atomic', 'true');
            const close = document.createElement('button');
            close.type = 'button';
            close.className = '-mt-2 -mr-2 flex size-11 shrink-0 items-center justify-center rounded-xl text-xl hover:bg-black/5';
            close.textContent = '×';
            close.setAttribute('aria-label', 'Tutup pemberitahuan');
            node.append(icon, body, close);
            stack.append(node);
            const entry = { node, close, remaining: settings.duration, started: null, timer: null, hovered: false, closing: false };
            active.add(entry);
            requestAnimationFrame(() => {
                if (entry.closing) return;
                const title = document.createElement('p');
                title.className = 'mb-1 text-sm font-bold';
                title.textContent = settings.label;
                body.append(title, content);
                sync(entry);
            });
            close.addEventListener('click', () => dismiss(entry));
            node.addEventListener('pointerenter', () => { entry.hovered = true; sync(entry); });
            node.addEventListener('pointerleave', () => { entry.hovered = false; sync(entry); });
            node.addEventListener('focusin', () => sync(entry));
            node.addEventListener('focusout', () => queueMicrotask(() => sync(entry)));
            node.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') { event.stopPropagation(); dismiss(entry); }
            });
        }
    }

    function enqueue(content, type = 'info', id = null) {
        if (id && seen.has(id)) return;
        if (id) seen.add(id);
        queue.push({ content, type });
        pump();
    }

    function message(text, type = 'info') {
        if (typeof text !== 'string' || !text.trim()) return;
        const paragraph = document.createElement('p');
        paragraph.className = 'text-sm leading-6 wrap-anywhere';
        paragraph.textContent = text;
        enqueue(paragraph, type);
    }

    system.querySelectorAll('template[data-toast]').forEach((template) => {
        enqueue(template.content.cloneNode(true), template.dataset.toastType, template.dataset.toastId);
        template.remove();
    });
    // Reusable UI API: dispatch CustomEvent('dapur:toast', { detail: { message, type } }).
    document.addEventListener('dapur:toast', (event) => message(event.detail?.message, event.detail?.type));

    const updateVisibility = () => {
        stack.hidden = navigationOpen();
        active.forEach(sync);
    };
    const observer = new MutationObserver(updateVisibility);
    ['profile-toggle', 'menu-toggle'].forEach((id) => {
        const button = document.getElementById(id);
        if (button) observer.observe(button, { attributes: true, attributeFilter: ['aria-expanded'] });
    });
    document.addEventListener('visibilitychange', updateVisibility);
    updateVisibility();
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            queue.length = 0;
            [...active].forEach((entry) => dismiss(entry, true));
        }
    });

    // No logout request is made here: show feedback only after the normal POST redirect.
    const logoutKey = 'dapur.ui.logout-pending';
    try {
        const pending = Number(sessionStorage.getItem(logoutKey));
        sessionStorage.removeItem(logoutKey);
        if (system.dataset.guest === 'true' && system.dataset.home === 'true'
            && pending > 0 && Date.now() - pending >= 0 && Date.now() - pending < 30000) {
            message('Kamu berhasil keluar. Sampai jumpa lagi!', 'success');
        }
    } catch { /* Storage disabled: skip optional logout feedback. */ }
    document.querySelectorAll('form[data-toast-logout]').forEach((form) => {
        form.addEventListener('submit', () => {
            try { sessionStorage.setItem(logoutKey, String(Date.now())); } catch { /* No auth impact. */ }
        });
    });
}
