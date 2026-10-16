import './toast-notifications';
import './auth-password';
import './recommendation-operation';
import './ingredient-checklist';
import './cooking-guide';
import './ingredient-input';

const menuButton = document.querySelector('#menu-toggle');
const menu = document.querySelector('#mobile-menu');
const profile = document.querySelector('[data-profile-dropdown]');
const profileButton = profile?.querySelector('#profile-toggle');
const profilePanel = profile?.querySelector('#profile-panel');

function closeProfile(restoreFocus = false) {
    if (!profilePanel || !profileButton) return;
    profilePanel.hidden = true;
    profileButton.setAttribute('aria-expanded', 'false');
    profileButton.setAttribute('aria-label', 'Buka informasi akun');
    if (restoreFocus) profileButton.focus();
}

function closeMenu() {
    menu.hidden = true;
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Buka menu navigasi');
}

menuButton?.addEventListener('click', () => {
    closeProfile();
    const expanded = menuButton.getAttribute('aria-expanded') === 'true';
    menu.hidden = expanded;
    menuButton.setAttribute('aria-expanded', String(!expanded));
    menuButton.setAttribute('aria-label', expanded ? 'Buka menu navigasi' : 'Tutup menu navigasi');
});
menu?.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMenu));
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && profilePanel && !profilePanel.hidden) {
        closeProfile(true);
        return;
    }
    if (event.key === 'Escape' && menu && !menu.hidden) {
        closeMenu();
        menuButton.focus();
    }
});

profileButton?.addEventListener('click', () => {
    const expanded = profileButton.getAttribute('aria-expanded') === 'true';
    if (expanded) {
        closeProfile();
        return;
    }
    if (menu && !menu.hidden) closeMenu();
    profilePanel.hidden = false;
    profileButton.setAttribute('aria-expanded', 'true');
    profileButton.setAttribute('aria-label', 'Tutup informasi akun');
});

document.addEventListener('click', (event) => {
    if (profile && !profile.contains(event.target)) closeProfile();
});

document.addEventListener('focusin', (event) => {
    if (profile && !profile.contains(event.target)) closeProfile();
});
