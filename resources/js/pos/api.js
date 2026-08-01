// Cliente minimo de la API del POS. Todo error llega como { status, code,
// message }: la app decide por CODIGO, nunca parseando mensajes.
const BASE = '/api/pos';

let token = localStorage.getItem('pos_token');

export function setToken(value) {
    token = value;
    if (value) {
        localStorage.setItem('pos_token', value);
    } else {
        localStorage.removeItem('pos_token');
    }
}

export function hasToken() {
    return Boolean(token);
}

async function request(method, path, body = null) {
    let response;
    try {
        response = await fetch(BASE + path, {
        method,
        headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
            body: body ? JSON.stringify(body) : null,
        });
    } catch {
        throw { status: 0, code: 'sin_red', message: 'Sin conexion: verifica la senal e intenta de nuevo.' };
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw { status: response.status, code: data.code ?? null, message: data.message ?? 'Error de red' };
    }

    return data;
}

export const api = {
    login: (username, password, device) => request('POST', '/login', { username, password, device_name: device }),
    logout: () => request('POST', '/logout'),
    bootstrap: () => request('GET', '/bootstrap'),
    catalog: () => request('GET', '/catalog'),
    openSession: (unitId, openingCents) => request('POST', '/sessions', { operating_unit_id: unitId, opening_cents: openingCents }),
    closeSession: (sessionId, countedCents) => request('POST', `/sessions/${sessionId}/close`, { counted_cents: countedCents }),
    syncOrder: (payload) => request('POST', '/orders', payload),
};
