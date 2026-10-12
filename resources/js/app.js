import './ingredient-checklist';
import './cooking-guide';
import './ingredient-input';
import './recipe-images';

const menuButton = document.querySelector('#menu-toggle');
const menu = document.querySelector('#mobile-menu');

function closeMenu() {
    menu.hidden = true;
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Buka menu navigasi');
}

menuButton?.addEventListener('click', () => {
    const expanded = menuButton.getAttribute('aria-expanded') === 'true';
    menu.hidden = expanded;
    menuButton.setAttribute('aria-expanded', String(!expanded));
    menuButton.setAttribute('aria-label', expanded ? 'Buka menu navigasi' : 'Tutup menu navigasi');
});
menu?.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMenu));
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && menu && !menu.hidden) {
        closeMenu();
        menuButton.focus();
    }
});
