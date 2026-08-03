// Cliente de la API de comandas. Todo error llega como { status, code,
// message }: la app decide por CODIGO, nunca parseando mensajes.
//
// Las claves de almacenamiento son PROPIAS y no las del POS aunque compartan
// origen: si fueran las mismas, el logout del cajero mataria la sesion de la
// cocina en mitad del servicio.
import { leerBateria } from './bateria';

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

/**
 * La bateria viaja EN CABECERA y no en la URL, y esa es toda la decision.
 *
 * Metida en la query seria un parametro mas que cambia cada pocos minutos, y
 * con el cambiaria la URL: adios al If-None-Match, adios al 304, y cada
 * tablet se bajaria el tablero entero cada tres segundos toda la noche. En
 * cabecera no toca ni el ETag ni la cache, y el servidor la lee de paso.
 *
 * Nunca lanza: si no hay dato, no hay cabeceras y ya esta. El sondeo del
 * tablero no se rompe por no saber la propia bateria.
 */
async function cabecerasDeBateria() {
    const lectura = await leerBateria().catch(() => null);

    if (!lectura) return {};

    const cabeceras = { 'X-Kds-Bateria': String(lectura.nivel) };

    // Se OMITE cuando no se sabe, en vez de mandar un 0: para el servidor un
    // hueco es «no lo se» y un 0 es «desenchufada», y no son lo mismo.
    if (lectura.cargando !== null) {
        cabeceras['X-Kds-Cargando'] = lectura.cargando ? '1' : '0';
    }

    return cabeceras;
}

export const api = {
    // El alta de la tablet: se hace UNA vez, en el montaje del evento.
    enrolar: (payload) => request('POST', '/enrolar', payload),
    salir: () => request('POST', '/salir'),
    comandas: async (etag) => request('GET', '/comandas', null, {
        ...(etag ? { 'If-None-Match': etag } : {}),
        ...(await cabecerasDeBateria()),
    }),
    avanzar: (orderId, area, from, to) => request('POST', `/comandas/${orderId}/${area}/estado`, { from, to }),
    buscar: (q) => request('GET', `/buscar?q=${encodeURIComponent(q)}`),
};
