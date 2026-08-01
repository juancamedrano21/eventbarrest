import Dexie from 'dexie';

// La memoria local del dispositivo: el catalogo para vender sin senal y la
// bandeja de salida con cada venta hasta que el servidor la confirme.
export const db = new Dexie('eventbarrest-pos');

db.version(1).stores({
    outbox: '++id, client_ref, status, created_at',
    kv: 'key',
});

export async function kvGet(key, fallback = null) {
    const row = await db.kv.get(key);
    return row ? row.value : fallback;
}

export async function kvSet(key, value) {
    await db.kv.put({ key, value });
}
