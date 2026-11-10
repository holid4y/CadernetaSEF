const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const WAIT_MS = 400;

// Someone whose account has no password yet finds out while typing the
// e-mail. If the question fails, the form just stays as it was.
export default (statusUrl, createUrl) => ({
    awaiting: false,
    email: '',
    timer: null,

    init() {
        this.changed(this.$refs.email.value);
    },

    get createLink() {
        return `${createUrl}?email=${encodeURIComponent(this.email)}`;
    },

    changed(value) {
        const email = value.trim().toLowerCase();

        if (email === this.email) {
            return;
        }

        clearTimeout(this.timer);
        this.email = email;
        this.awaiting = false;

        if (EMAIL.test(email)) {
            this.timer = setTimeout(() => this.check(email), WAIT_MS);
        }
    },

    async check(email) {
        const answer = await fetch(statusUrl, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({ email }),
        }).then((response) => response.json()).catch(() => null);

        if (email === this.email) {
            this.awaiting = answer?.awaiting_password === true;
        }
    },
});
