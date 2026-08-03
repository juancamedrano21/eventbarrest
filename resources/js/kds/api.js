// Cliente de la API de comandas. Todo error llega como { status, code,
// message }: la app decide por CODIGO, nunca parseando mensajes.
//
// Las claves de almacenamiento son PROPIAS y no las del POS aunque compartan
// origen: si fueran las mismas, el logout del cajero mataria la sesion de la
// cocina en mitad del servicio.
const BASE = '/api/kds';

let token = localStorage.getItem('kds_token');

export function setToken(value) {
    token = value;
    if (value) {
        localStorage.setItem('kds_token', value);
    } else {
        localStorage.removeItem('kds_token');
    }
}

export function hasToken() {
    return Boolean(token);
}

async function request(method, path, body = null, extraHeaders = {}) {
    let response;
    try {
        response = await fetch(BASE + path, {
            method,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...extraHeaders,
            },
            body: body ? JSON.stringify(body) : null,
        });
    } catch {
        throw { status: 0, code: 'sin_red', message: 'Sin conexion con el servidor.' };
    }

    // 304: nada cambio desde el ultimo vistazo. No es un error ni trae
    // cuerpo — la pantalla se queda como esta y ahorra el payload entero.
    if (response.status === 304) {
        return { sinCambios: true, etag: response.headers.get('ETag') };
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw { status: response.status, code: data.code ?? null, message: data.message ?? 'Error de red', data };
    }

    return { ...data, etag: response.headers.get('ETag') };
}

export const api = {
    // El alta de la tablet: se hace UNA vez, en el montaje del evento.
    enrolar: (payload) => request('POST', '/enrolar', payload),
    salir: () => request('POST', '/salir'),
    comandas: (etag) => request('GET', '/comandas', null, etag ? { 'If-None-Match': etag } : {}),
    avanzar: (orderId, area, from, to) => request('POST', `/comandas/${orderId}/${area}/estado`, { from, to }),
    buscar: (q) => request('GET', `/buscar?q=${encodeURIComponent(q)}`),
};
