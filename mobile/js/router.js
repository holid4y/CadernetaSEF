import { store } from './store.js';

const routes = [];
let outlet = null;
let current = null;
const stack = [];
let goingBack = false;

export const route = (pattern, view, options = {}) => {
    const keys = [];
    const source = pattern.replace(/:(\w+)/g, (_, key) => {
        keys.push(key);
        return '([^/]+)';
    });
    routes.push({ regex: new RegExp(`^${source}$`), keys, view, ...options });
};

const parse = () => {
    const raw = location.hash.slice(1) || '/';
    const [path, search = ''] = raw.split('?');
    return { path, query: Object.fromEntries(new URLSearchParams(search)) };
};

export const navigate = (hash, { replace = false } = {}) => {
    if (replace) location.replace(`#${hash}`);
    else location.hash = hash;
};

export const back = (fallback = '/') => {
    if (stack.length > 1) history.back();
    else navigate(fallback, { replace: true });
};

export function refresh() {
    render({ keepScroll: true });
}

function render({ keepScroll = false } = {}) {
    const { path, query } = parse();
    const match = routes.find((r) => r.regex.test(path));
    const user = store.user;

    if (!match) return navigate('/', { replace: true });
    if (!match.public && !user) return navigate('/login', { replace: true });
    if (match.public && user) return navigate('/', { replace: true });

    if (match.public) document.getElementById('overlay').innerHTML = '';

    const params = Object.fromEntries(match.keys.map((key, i) => [key, decodeURIComponent(path.match(match.regex)[i + 1])]));
    const scroll = keepScroll ? outlet.scrollTop : 0;
    const result = match.view({ params, query, user, state: store.state });

    outlet.innerHTML = `<div class="page ${keepScroll ? 'is-refresh' : goingBack ? 'is-back' : ''} ${match.public ? 'is-public' : ''}">${result.html}</div>`;
    outlet.scrollTop = scroll;
    current = { match, path };
    result.mount?.(outlet.firstElementChild);

    document.getElementById('tabbar').hidden = !match.tab;
    document.querySelectorAll('#tabbar [data-tab]').forEach((tab) => {
        if (tab.dataset.tab === match.tab) tab.setAttribute('aria-current', 'page');
        else tab.removeAttribute('aria-current');
    });
    document.querySelector('.tab-new')?.classList.toggle('is-off', user?.role === 'viewer');

    if (!keepScroll) outlet.focus({ preventScroll: true });
    goingBack = false;
}

export function start(element) {
    outlet = element;

    window.addEventListener('hashchange', () => {
        const hash = location.hash;
        if (stack.length > 1 && stack[stack.length - 2] === hash) {
            stack.pop();
            goingBack = true;
        } else {
            stack.push(hash);
        }
        render();
    });

    outlet.addEventListener('click', (event) => {
        const button = event.target.closest('[data-back]');
        if (button) back(button.dataset.back);
    });

    stack.push(location.hash);
    render();
}

export const currentPath = () => current?.path ?? '/';

export const onTabPage = () => Boolean(current?.match.tab);
