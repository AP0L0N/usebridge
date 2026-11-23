{{--
    Theme on first paint: the stored choice (localStorage "bridge-theme": light | dark), else the system preference.
    Sets data-theme and color-scheme on <html> before any stylesheet loads, so the page never flashes the other theme.
    Keep it in <head>, above @vite and every <link rel="stylesheet">. No "!" in it: the landing copy test bans the character. resources/js/lib/theme.ts reads and changes the same key.
--}}
<script>(function(){var d=document.documentElement,t=null;try{t=localStorage.getItem('bridge-theme')}catch(e){}t=t==='light'||t==='dark'?t:window.matchMedia&&window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';d.setAttribute('data-theme',t);d.style.colorScheme=t})()</script>
