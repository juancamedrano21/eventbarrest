// Centavos a pesos dominicanos, siempre por la misma puerta.
export function money(cents) {
    return new Intl.NumberFormat('es-DO', { style: 'currency', currency: 'DOP' }).format((cents ?? 0) / 100);
}
