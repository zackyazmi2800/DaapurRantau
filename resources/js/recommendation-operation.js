// A changed payload is a new operation. Re-submitting unchanged input retains
// its key, including browser back/retry. Never store ingredient data in storage.
document.querySelectorAll('form[data-recommendation-operation]').forEach(form => {
    const key = form.querySelector('[name="idempotency_key"]');
    const renew = () => {
        const bytes = new Uint8Array(16);
        crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 15) | 64;
        bytes[8] = (bytes[8] & 63) | 128;
        const hex = [...bytes].map(value => value.toString(16).padStart(2, '0')).join('');
        key.value = `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };
    form.addEventListener('input', event => {
        if (['servings', 'preference', 'allergies'].includes(event.target.name)) renew();
    });
    form.addEventListener('change', event => {
        if (['servings', 'preference', 'allergies', 'confirmed[]'].includes(event.target.name)) renew();
    });
    form.addEventListener('ingredients-changed', renew);
});
