{{-- Light / dark / system toggle (resources/js/landing.ts wires it to lib/theme.ts; the icons match ThemeToggle.tsx). --}}
<button class="theme-toggle" type="button" data-theme-toggle aria-label="Theme" title="Theme">
    <svg class="theme-toggle-system" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2.75" y="3.75" width="14.5" height="10" rx="1.75"/><path d="M7 16.75H13M10 13.75V16.75"/></svg>
    <svg class="theme-toggle-light" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="10" cy="10" r="3.5"/><path d="M10 2.5V4M10 16V17.5M2.5 10H4M16 10H17.5M4.7 4.7L5.76 5.76M14.24 14.24L15.3 15.3M4.7 15.3L5.76 14.24M14.24 5.76L15.3 4.7"/></svg>
    <svg class="theme-toggle-dark" width="20" height="20" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16.5 11.9A6.75 6.75 0 1 1 8.1 3.5a5.25 5.25 0 0 0 8.4 8.4Z"/></svg>
</button>
