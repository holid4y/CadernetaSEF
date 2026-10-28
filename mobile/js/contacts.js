const areaCodes = new Set(
    '11 12 13 14 15 16 17 18 19 21 22 24 27 28 31 32 33 34 35 37 38 41 42 43 44 45 46 47 48 49 51 53 54 55 61 62 63 64 65 66 67 68 69 71 73 74 75 77 79 81 82 83 84 85 86 87 88 89 91 92 93 94 95 96 97 98 99'.split(
        ' ',
    ),
);

export function phoneNumber(value) {
    const raw = String(value ?? '').trim();
    if (!/^(\+)?[\d\s().-]+$/.test(raw)) return null;
    let digits = raw.replace(/\D/g, '');
    if (/^(\d)\1+$/.test(digits)) return null;
    if (raw.startsWith('+') && !digits.startsWith('55')) return /^[1-9]\d{7,14}$/.test(digits) ? digits : null;
    if (raw.startsWith('+55') && ![12, 13].includes(digits.length)) return null;
    if (digits.length > 11 && digits.startsWith('55')) digits = digits.slice(2);
    if (!areaCodes.has(digits.slice(0, 2))) return null;
    if (!/^(?:\d{2}[2-5]\d{7}|\d{2}9\d{8})$/.test(digits)) return null;
    return `55${digits}`;
}

export function emailAddress(value) {
    const email = String(value ?? '').trim();
    if (email.length > 254 || !/^[a-z0-9.!#$%&'*+/=?^_`{|}~-]+@[a-z0-9.-]+$/i.test(email)) return null;
    const [local, domain] = email.split('@');
    if (local.length > 64 || local.startsWith('.') || local.endsWith('.') || local.includes('..')) return null;
    const labels = domain.split('.');
    if (labels.length < 2 || labels.some((label) => !/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i.test(label)))
        return null;
    return email;
}

export function emailLink(value, subject = '', body = '') {
    const email = emailAddress(value);
    return email
        ? `mailto:${encodeURIComponent(email).replace('%40', '@')}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`
        : null;
}

export function whatsappLink(value, message = '') {
    const number = phoneNumber(value);
    return number ? `https://wa.me/${number}?text=${encodeURIComponent(message)}` : null;
}
