/** Icons of the redesigned app shell, exact geometry from the Figma export (sizes/viewBox kept). Colours via CSS (currentColor). */
const P = { fill: 'none', strokeLinecap: 'round', strokeLinejoin: 'round' } as const;

export const LogoMark = () => (
  <svg width="28" height="28" viewBox="0 0 28 28" fill="none" aria-hidden="true">
    <rect width="28" height="28" rx="6.41667" fill="#1C1E24" />
    <path d="M7 17.2083C7 13.3422 10.1339 10.2083 14 10.2083C17.8661 10.2083 21 13.3422 21 17.2083" stroke="white" strokeWidth="3.20833" strokeLinecap="round" />
    <circle cx="7" cy="17.2083" r="3.20833" fill="white" />
    <circle cx="21" cy="17.2083" r="3.20833" fill="#C8F135" />
  </svg>
);
export const IconChev = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M4 6L8 10L12 6" stroke="currentColor" strokeWidth="1.16667" {...P} /></svg>
);
export const IconMore = () => (
  <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
    <path d="M5.16667 10C5.16667 10.5523 4.71895 11 4.16667 11C3.61438 11 3.16667 10.5523 3.16667 10C3.16667 9.44772 3.61438 9 4.16667 9C4.71895 9 5.16667 9.44772 5.16667 10Z" stroke="currentColor" strokeWidth="1.45833" {...P} />
    <path d="M11 10C11 10.5523 10.5523 11 10 11C9.44772 11 9 10.5523 9 10C9 9.44772 9.44772 9 10 9C10.5523 9 11 9.44772 11 10Z" stroke="currentColor" strokeWidth="1.45833" {...P} />
    <path d="M16.8333 10C16.8333 10.5523 16.3856 11 15.8333 11C15.281 11 14.8333 10.5523 14.8333 10C14.8333 9.44772 15.281 9 15.8333 9C16.3856 9 16.8333 9.44772 16.8333 10Z" stroke="currentColor" strokeWidth="1.45833" {...P} />
  </svg>
);
export const IconSearch20 = () => (
  <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
    <path d="M15 9.16667C15 12.3883 12.3883 15 9.16667 15C5.94501 15 3.33333 12.3883 3.33333 9.16667C3.33333 5.94501 5.94501 3.33333 9.16667 3.33333C12.3883 3.33333 15 5.94501 15 9.16667Z" stroke="currentColor" strokeWidth="1.45833" {...P} />
    <path d="M16.6667 16.6667L13.75 13.75" stroke="currentColor" strokeWidth="1.45833" {...P} />
  </svg>
);
export const IconSearch24 = () => (
  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path d="M18 11C18 14.866 14.866 18 11 18C7.13401 18 4 14.866 4 11C4 7.13401 7.13401 4 11 4C14.866 4 18 7.13401 18 11Z" stroke="currentColor" strokeWidth="1.75" {...P} />
    <path d="M20 20L16.5 16.5" stroke="currentColor" strokeWidth="1.75" {...P} />
  </svg>
);
export const IconX = () => (
  <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><path d="M13.5 4.5L9 9M9 9L4.5 13.5M9 9L4.5 4.5M9 9L13.5 13.5" stroke="currentColor" strokeWidth="1.3125" {...P} /></svg>
);
export const IconInbox24 = () => (
  <svg width="24" height="24" viewBox="0 0 24 24" fill="none" aria-hidden="true">
    <path d="M22 12H16L14 15H10L8 12H2" stroke="currentColor" strokeWidth="1.75" {...P} />
    <path d="M5.45 5.11L2 12V18C2 18.5304 2.21071 19.0391 2.58579 19.4142C2.96086 19.7893 3.46957 20 4 20H20C20.5304 20 21.0391 19.7893 21.4142 19.4142C21.7893 19.0391 22 18.5304 22 18V12L18.55 5.11C18.3844 4.77679 18.1292 4.49637 17.813 4.30028C17.4967 4.10419 17.1321 4.0002 16.76 4H7.24C6.86792 4.0002 6.50326 4.10419 6.18704 4.30028C5.87083 4.49637 5.61558 4.77679 5.45 5.11Z" stroke="currentColor" strokeWidth="1.75" {...P} />
  </svg>
);
export const IconArchive14 = () => (
  <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
    <path d="M1.75 1.75H12.25C12.5722 1.75 12.8333 2.01117 12.8333 2.33333V4.08333C12.8333 4.4055 12.5722 4.66667 12.25 4.66667H1.75C1.42783 4.66667 1.16667 4.4055 1.16667 4.08333V2.33333C1.16667 2.01117 1.42783 1.75 1.75 1.75Z" stroke="currentColor" strokeWidth="1.02083" {...P} />
    <path d="M2.33333 4.66667V11.0833C2.33333 11.3928 2.45625 11.6895 2.67504 11.9083C2.89383 12.1271 3.19058 12.25 3.5 12.25H10.5C10.8094 12.25 11.1062 12.1271 11.325 11.9083C11.5438 11.6895 11.6667 11.3928 11.6667 11.0833V4.66667M5.83333 7H8.16667" stroke="currentColor" strokeWidth="1.02083" {...P} />
  </svg>
);
export const IconArchive18 = () => (
  <svg width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true">
    <path d="M2.25 2.25H15.75C16.1642 2.25 16.5 2.58579 16.5 3V5.25C16.5 5.66421 16.1642 6 15.75 6H2.25C1.83579 6 1.5 5.66421 1.5 5.25V3C1.5 2.58579 1.83579 2.25 2.25 2.25Z" stroke="currentColor" strokeWidth="1.3125" {...P} />
    <path d="M3 6V14.25C3 14.6478 3.15804 15.0294 3.43934 15.3107C3.72064 15.592 4.10218 15.75 4.5 15.75H13.5C13.8978 15.75 14.2794 15.592 14.5607 15.3107C14.842 15.0294 15 14.6478 15 14.25V6M7.5 9H10.5" stroke="currentColor" strokeWidth="1.3125" {...P} />
  </svg>
);
export const IconClock14 = () => (
  <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
    <path d="M12.25 7C12.25 9.8995 9.8995 12.25 7 12.25C4.10051 12.25 1.75 9.8995 1.75 7C1.75 4.10051 4.10051 1.75 7 1.75C9.8995 1.75 12.25 4.10051 12.25 7Z" stroke="currentColor" strokeWidth="1.02083" {...P} />
    <path d="M7 4.08333V7L8.75 8.16667" stroke="currentColor" strokeWidth="1.02083" {...P} />
  </svg>
);
export const IconCheck14 = () => (
  <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M11.6667 3.5L5.25 9.91667L2.33333 7" stroke="currentColor" strokeWidth="1.02083" {...P} /></svg>
);
export const IconDash14 = () => (
  <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
    <path d="M11.5778 6.08924C11.6361 6.38381 11.6667 6.68833 11.6667 7C11.6667 7.31167 11.6361 7.61619 11.5778 7.91076M10.8806 4.40713C10.5399 3.89822 10.1018 3.46007 9.59287 3.11936M7.91076 2.42216C7.61619 2.36389 7.31167 2.33333 7 2.33333C6.68833 2.33333 6.38381 2.36389 6.08924 2.42216M4.40713 3.11936C3.89822 3.46007 3.46007 3.89822 3.11936 4.40713M2.42216 6.08924C2.36389 6.38381 2.33333 6.68833 2.33333 7C2.33333 7.31167 2.36389 7.61619 2.42216 7.91076M3.11936 9.59287C3.46007 10.1018 3.89822 10.5399 4.40713 10.8806M6.08924 11.5778C6.38381 11.6361 6.68833 11.6667 7 11.6667C7.31167 11.6667 7.61619 11.6361 7.91076 11.5778M9.59287 10.8806C10.1018 10.5399 10.5399 10.1018 10.8806 9.59287" stroke="currentColor" strokeWidth="1.02083" {...P} />
  </svg>
);
