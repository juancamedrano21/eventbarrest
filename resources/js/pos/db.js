import Dexie from 'dexie';

// La memoria local del dispositivo: el catalogo para vender sin senal y la
// bandeja de salida con cada venta hasta que el servidor la confirme.
export const db = new Dexie('eventbarrest-pos');

db.version(1).stores({
    outbox: '++id, client_ref, status, created_at',
    kv: 'key',
});

export async function kvGet(key, fallback = null) {
    // El fallback aplica tambien si la fila existe con valor null (un
    // logout guarda null): quien pide un {} de respaldo lo recibe siempre.
    const row = await db.kv.get(key);
    return row?.value ?? fallback;
}

export async function kvSet(key, value) {
    // Lo que llega puede venir envuelto en un proxy reactivo de Vue (leer
    // this.session desde el store devuelve un proxy) y el structured clone
    // de IndexedDB no clona proxies: DataCloneError. Todo lo que se guarda
    // aqui nacio como JSON del API, asi que el viaje de ida y vuelta lo
    // devuelve a objeto plano sin perder nada.
    await db.kv.put({ key, value: value == null ? null : JSON.parse(JSON.stringify(value)) });
}
