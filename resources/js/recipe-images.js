document.querySelectorAll('[data-recipe-image]').forEach((image) => {
    const fallback = () => {
        if (image.dataset.fallbackUsed === 'true') return;
        image.dataset.fallbackUsed = 'true';
        image.alt = 'Ilustrasi peralatan masak; foto masakan belum tersedia';
        image.src = image.dataset.fallback;
        const credit = image.closest('[data-recipe-photo]')?.querySelector('[data-photo-credit]');
        if (credit) credit.hidden = true;
    };
    image.addEventListener('error', fallback);
    if (image.complete && image.naturalWidth === 0) fallback();
});
