import { config } from './config.js';
import { createApi } from './api.js';
import * as domain from './domain.js';
import { buildSeed } from './seed.js';
import { dayKey } from './util.js';

const DATA_KEY = 'agenda-contabil.demo.v1';
const SESSION_KEY = 'agenda-contabil.session.v1';

const read = (key) => {
    try {
        return localStorage.getItem(key);
    } catch {
        return null;
    }
};

const write = (key, value) => {
    try {
        if (value === null) localStorage.removeItem(key);
        else localStorage.setItem(key, value);
    } catch {}
};

const load = () => {
    const raw = read(DATA_KEY);
    if (raw) {
        try {
            const saved = JSON.parse(raw);
            const stale = !saved.meta.edited && saved.meta.seededOn !== dayKey(Date.now());
            if (saved.version === 1 && !stale) return saved;
        } catch {}
    }
    const fresh = buildSeed();
    write(DATA_KEY, JSON.stringify(fresh));
    return fresh;
};

const empty = () => ({ users: [], clients: [], services: [], appointments: [], counters: {}, meta: {} });
const validState = (value) => value && ['users', 'clients', 'services', 'appointments'].every((key) => Array.isArray(value[key]));
const api = createApi(config.apiUrl);
let state = config.mode === 'demo' ? load() : empty();
let userId = config.mode === 'demo' ? Number(read(SESSION_KEY)) || null : null;
let pending = false;
let loadedAt = 0;
const listeners = new Set();

// The server refuses (409) a change made over data someone else has just
// modified, so each command also carries what this device was showing.
const SEEN = {
    changeStatus: ([id, status]) => [id, status, domain.appointmentOf(state, id)?.status ?? null],
    rescheduleAppointment: ([id, input]) => [id, { ...input, updatedAt: domain.appointmentOf(state, id)?.updatedAt ?? null }],
    saveClient: ([input, id = null]) => [{ ...input, updatedAt: id === null ? null : domain.clientOf(state, id)?.updatedAt ?? null }, id],
    toggleDocument: ([appointmentId, documentId]) => [
        appointmentId,
        documentId,
        !domain.appointmentOf(state, appointmentId)?.documents.find((d) => d.id === documentId)?.receivedAt,
    ],
    removeServiceDocument: ([slug, index]) => [slug, index, domain.serviceOf(state, slug)?.documents[index] ?? null],
    restoreAppointment: ([snapshot]) => [{ id: snapshot.id, status: snapshot.status }],
};

const withSeen = (name, args) => (SEEN[name] ? SEEN[name](args) : args);

const persist = () => write(DATA_KEY, JSON.stringify(state));

export const store = {
    get isDemo() {
        return config.mode === 'demo';
    },

    async action(name, ...args) {
        if (!this.isDemo) {
            if (pending) throw new domain.ValidationError('Aguarde a operação em andamento.');
            pending = true;
            try {
                const response = await api.request('/mobile/commands', { method: 'POST', body: { name, args: withSeen(name, args) } });
                if (!validState(response?.state) || !Object.prototype.hasOwnProperty.call(response, 'result')) throw new domain.ValidationError('Resposta inválida do servidor.');
                state = response.state;
                loadedAt = Date.now();
                listeners.forEach((listener) => listener());
                return response.result;
            } catch (error) {
                if (error.status === 401) {
                    state = empty();
                    userId = null;
                    location.hash = '/login';
                }
                throw error;
            } finally {
                pending = false;
            }
        }
        if (name === 'restoreAppointment') return this.commit((s) => domain[name](s, ...args));
        return this.commit((s, u) => domain[name](s, u, ...args));
    },

    async reload() {
        if (this.isDemo) return;
        if (pending) throw new domain.ValidationError('Aguarde a operação em andamento.');
        pending = true;
        try {
            const response = await api.request('/mobile/bootstrap');
            if (!validState(response?.state)) throw new domain.ValidationError('Resposta inválida do servidor.');
            state = response.state;
            loadedAt = Date.now();
        } catch (error) {
            if (error.status === 401) {
                state = empty();
                userId = null;
                location.hash = '/login';
            }
            throw error;
        } finally {
            pending = false;
        }
    },

    async forgotPassword(email) {
        if (this.isDemo) return;
        await api.request('/forgot-password', { method: 'POST', body: { email } });
    },
    get state() {
        return state;
    },

    get loadedAt() {
        return loadedAt;
    },

    get user() {
        return state.users.find((u) => u.id === userId && u.active) ?? null;
    },

    subscribe(listener) {
        listeners.add(listener);
        return () => listeners.delete(listener);
    },

    commit(mutation) {
        if (!this.isDemo) throw new Error('Use store.action in server mode.');
        const result = mutation(state, this.user);
        persist();
        listeners.forEach((listener) => listener());
        return result;
    },

    async login(email, password) {
        if (!this.isDemo) {
            try {
                const auth = await api.request('/login', { method: 'POST', body: { email: email.trim().toLowerCase(), password, device_name: 'Caderneta SEF mobile' } });
                if (!auth?.token || !auth?.user?.id) throw new domain.ValidationError('Resposta inválida do servidor.');
                api.setToken(auth.token);
                const response = await api.request('/mobile/bootstrap');
                if (!validState(response?.state) || !response.state.users.some((u) => u.id === auth.user.id && u.active)) throw new domain.ValidationError('Resposta inválida do servidor.');
                state = response.state;
                userId = auth.user.id;
                loadedAt = Date.now();
                return this.user;
            } catch (error) {
                api.clear();
                state = empty();
                userId = null;
                throw error;
            }
        }
        const user = state.users.find((u) => u.email === email.trim().toLowerCase() && u.active);
        if (!user || password !== 'password') return null;
        userId = user.id;
        write(SESSION_KEY, String(user.id));
        return user;
    },

    switchUser(id) {
        if (!this.isDemo) return;
        userId = id;
        write(SESSION_KEY, String(id));
        listeners.forEach((listener) => listener());
    },

    async logout() {
        if (!this.isDemo) {
            try {
                await api.request('/logout', { method: 'POST' });
            } finally {
                api.clear();
                state = empty();
                userId = null;
            }
            return;
        }
        userId = null;
        write(SESSION_KEY, null);
    },

    reset() {
        if (!this.isDemo) return;
        state = buildSeed();
        persist();
        listeners.forEach((listener) => listener());
    },
};
