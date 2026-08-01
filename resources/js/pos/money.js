// Centavos a pesos dominicanos, siempre por la misma puerta.
export function money(cents) {
    return new Intl.NumberFormat('es-DO', { style: 'currency', currency: 'DOP' }).format((cents ?? 0) / 100);
}

// Pesos digitados a centavos: acepta coma o punto decimal (teclados es-DO).
// En RD la coma tambien es separador de miles (1,000 o 1,000.50): si el
// numero tiene esa forma, las comas se descartan en vez de volverse un
// decimal que convierte mil pesos en uno.
export function toCents(value) {
    let digits = String(value ?? '').trim();

    if (/^\d{1,3}(,\d{3})+(\.\d+)?$/.test(digits)) {
        digits = digits.replace(/,/g, '');
    }

    const parsed = Number(digits.replace(',', '.'));
    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
}
