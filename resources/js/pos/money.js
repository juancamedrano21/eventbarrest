// Centavos a pesos dominicanos, siempre por la misma puerta.
export function money(cents) {
    return new Intl.NumberFormat('es-DO', { style: 'currency', currency: 'DOP' }).format((cents ?? 0) / 100);
}

// Pesos digitados a centavos: acepta coma o punto decimal (teclados es-DO).
export function toCents(value) {
    const parsed = Number(String(value ?? '').replace(',', '.'));
    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
}
