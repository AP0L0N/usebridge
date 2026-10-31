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

/* Kanban screen (02): exact geometry from the Figma icon components (stroke via currentColor). */
export const IconLeft16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 3.33333L3.33333 8L8 12.6667M3.33333 8H12.6667" stroke="currentColor" strokeWidth="1.33333" {...P} /></svg>
);
export const IconSearch16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
    <path d="M12 7.33333C12 9.91066 9.91066 12 7.33333 12C4.756 12 2.66667 9.91066 2.66667 7.33333C2.66667 4.756 4.756 2.66667 7.33333 2.66667C9.91066 2.66667 12 4.756 12 7.33333Z" stroke="currentColor" strokeWidth="1.33333" {...P} />
    <path d="M13.3333 13.3333L11 11" stroke="currentColor" strokeWidth="1.33333" {...P} />
  </svg>
);
export const IconFilter16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M2 4H14M4.66667 8H11.3333M6.66667 12H9.33333" stroke="currentColor" strokeWidth="1.33333" {...P} /></svg>
);
export const IconPlus16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.33333 8H8M8 8H12.6667M8 8V3.33333M8 8V12.6667" stroke="currentColor" strokeWidth="1.33333" {...P} /></svg>
);
export const IconMsg12 = () => (
  <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
    <path d="M10.5 6C10.4962 6.68101 10.3187 7.34978 9.98411 7.94296C9.64956 8.53613 9.16909 9.03407 8.58824 9.38959C8.00739 9.74511 7.34538 9.94645 6.66494 9.97452C5.9845 10.0026 5.30816 9.85647 4.7 9.55L1.5 10.5L2.45 7.3C2.36464 6.77143 2.38423 6.23122 2.50764 5.71022C2.63105 5.18922 2.85588 4.69763 3.16928 4.26351C3.48267 3.8294 3.87851 3.46127 4.33418 3.18013C4.78985 2.89899 5.29643 2.71036 5.825 2.625C6.35357 2.53964 6.89378 2.55923 7.41478 2.68264C7.93578 2.80605 8.42737 3.03088 8.86149 3.34428C9.2956 3.65767 9.66373 4.05351 9.94487 4.50918C10.226 4.96485 10.4146 5.47143 10.5 6Z" stroke="currentColor" {...P} />
  </svg>
);
export const IconCal12 = () => (
  <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
    <path d="M2.5 2.5H9.5C10.0523 2.5 10.5 2.94772 10.5 3.5V9.5C10.5 10.0523 10.0523 10.5 9.5 10.5H2.5C1.94772 10.5 1.5 10.0523 1.5 9.5V3.5C1.5 2.94772 1.94772 2.5 2.5 2.5Z" stroke="currentColor" {...P} />
    <path d="M8 1.5V3.5M4 1.5V3.5M1.5 5H10.5" stroke="currentColor" {...P} />
  </svg>
);
export const IconCal16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
    <path d="M3.33333 3.33333H12.6667C13.403 3.33333 14 3.93029 14 4.66667V12.6667C14 13.403 13.403 14 12.6667 14H3.33333C2.59695 14 2 13.403 2 12.6667V4.66667C2 3.93029 2.59695 3.33333 3.33333 3.33333Z" stroke="currentColor" strokeWidth="1.33333" {...P} />
    <path d="M10.6667 2V4.66667M5.33333 2V4.66667M2 6.66667H14" stroke="currentColor" strokeWidth="1.33333" {...P} />
  </svg>
);
export const IconHist16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">
    <path d="M2 8C1.99875 9.37403 2.46914 10.7069 3.33255 11.7757C4.19596 12.8446 5.40008 13.5848 6.74366 13.8725C8.08723 14.1602 9.48885 13.978 10.7143 13.3564C11.9397 12.7349 12.9146 11.7115 13.4761 10.4575C14.0376 9.2034 14.1517 7.79461 13.7993 6.46654C13.4469 5.13847 12.6493 3.9716 11.5399 3.16095C10.4304 2.3503 9.07639 1.945 7.70403 2.01278C6.33166 2.08055 5.02414 2.61731 4 3.53333L2 5.33333" stroke="currentColor" strokeWidth="1.33333" {...P} />
    <path d="M2 2V5.33333H5.33333M8 4.66667V8L10 9.33333" stroke="currentColor" strokeWidth="1.33333" {...P} />
  </svg>
);
export const IconMove16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8.66667 10.6667L11.3333 13.3333L14 10.6667M11.3333 13.3333V2.66667M7.33333 5.33333L4.66667 2.66667L2 5.33333M4.66667 2.66667V13.3333" stroke="currentColor" strokeWidth="1.33333" {...P} /></svg>
);
export const IconInbox20 = () => (
  <svg width="20" height="20" viewBox="0 0 20 20" fill="none" aria-hidden="true">
    <path d="M18.3333 10H13.3333L11.6667 12.5H8.33333L6.66667 10H1.66667" stroke="currentColor" strokeWidth="1.66667" {...P} />
    <path d="M4.58333 4.16667H15.4167L18.3333 10V15C18.3333 15.442 18.1577 15.866 17.8452 16.1785C17.5326 16.4911 17.1087 16.6667 16.6667 16.6667H3.33333C2.89131 16.6667 2.46738 16.4911 2.15482 16.1785C1.84226 15.866 1.66667 15.442 1.66667 15V10L4.58333 4.16667Z" stroke="currentColor" strokeWidth="1.66667" {...P} />
  </svg>
);
export const IconEdit16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 13.3333H14M11 2.33333C11.2652 2.06812 11.6249 1.91912 12 1.91912C12.3751 1.91912 12.7348 2.06812 13 2.33333C13.2652 2.59855 13.4142 2.95826 13.4142 3.33333C13.4142 3.70841 13.2652 4.06812 13 4.33333L4.66667 12.6667L2 13.3333L2.66667 10.6667L11 2.33333Z" stroke="currentColor" strokeWidth="1.33333" {...P} /></svg>
);
export const IconX16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M12 4L8 8M8 8L4 12M8 8L4 4M8 8L12 12" stroke="currentColor" strokeWidth="1.33333" {...P} /></svg>
);
export const IconMore16 = () => (
  <svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3.33333 8H3.34M8 8H8.00667M12.6667 8H12.6733" stroke="currentColor" strokeWidth="1.33333" {...P} /></svg>
);
export const IconCheck12 = () => (
  <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true"><path d="M10 3L4.5 8.5L2 6" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" /></svg>
);

