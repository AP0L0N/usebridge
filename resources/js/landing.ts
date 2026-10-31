// Landing page progressive enhancement (the page works without it): mobile menu toggle and the sticky CTA.

const toggle = document.querySelector<HTMLButtonElement>('[data-menu-toggle]');
const sheet = document.getElementById('site-menu');
const closer = document.querySelector<HTMLButtonElement>('[data-menu-close]');

if (toggle && sheet) {
    const setOpen = (open: boolean): void => {
        toggle.setAttribute('aria-expanded', String(open));
        sheet.classList.toggle('is-open', open);
        document.body.classList.toggle('menu-locked', open);
        if (open) closer?.focus();
        else toggle.focus();
    };
    toggle.addEventListener('click', () => setOpen(true));
    closer?.addEventListener('click', () => setOpen(false));
    sheet.addEventListener('click', (event) => {
        if ((event.target as HTMLElement).closest('a')) {
            toggle.setAttribute('aria-expanded', 'false');
            sheet.classList.remove('is-open');
            document.body.classList.remove('menu-locked');
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sheet.classList.contains('is-open')) setOpen(false);
    });
}

const hero = document.getElementById('hero');
const sticky = document.querySelector<HTMLElement>('[data-sticky-cta]');

if (hero && sticky && 'IntersectionObserver' in window) {
    new IntersectionObserver(([entry]) => {
        const visible = !entry.isIntersecting;
        sticky.classList.toggle('is-visible', visible);
        sticky.toggleAttribute('inert', !visible);
    }).observe(hero);
}
