// Classic script in <head>: the theme is set before the first paint.
(function () {
    var pref = 'auto';
    try { pref = localStorage.getItem('agenda-contabil.theme') || 'auto'; } catch (e) {}
    var dark = pref === 'dark' || (pref === 'auto' && matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.dataset.theme = dark ? 'dark' : 'light';
})();
