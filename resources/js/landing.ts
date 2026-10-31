// Landing page progressive enhancement (the page works without it): mobile menu toggle and the sticky CTA.

const toggle = document.querySelector<HTMLButtonElement>('[data-menu-toggle]');
const nav = document.getElementById('site-nav');

if (toggle && nav) {
    const setOpen = (open: boolean): void => {
        toggle.setAttribute('aria-expanded', String(open));
        nav.classList.toggle('is-open', open);
    };
    toggle.addEventListener('click', () => setOpen(toggle.getAttribute('aria-expanded') !== 'true'));
    nav.addEventListener('click', (event) => {
        if ((event.target as HTMLElement).closest('a')) setOpen(false);
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
            setOpen(false);
            toggle.focus();
        }
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
