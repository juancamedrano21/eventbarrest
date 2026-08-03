<script setup>
import { computed } from 'vue';
import { usePos } from '../store';
import { money } from '../money';

const pos = usePos();

const props = defineProps({
    // El ticket congelado de la venta (sale.ticket) o el que se arma desde
    // el listado del servidor.
    ticket: { type: Object, required: true },
    // 'recibo' lleva precios y totales; 'comanda' va a cocina y barra y NO
    // los lleva: quien cocina no necesita saber cuánto costó, y verlo solo
    // añade ruido a un papel que se lee de un vistazo.
    kind: { type: String, default: 'recibo' },
    number: { type: String, default: null },
});

const fecha = computed(() => new Intl.DateTimeFormat('es-DO', {
    day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit',
}).format(props.ticket.printed_at ? new Date(props.ticket.printed_at) : new Date()));

const metodo = { cash: 'Efectivo', card: 'Tarjeta', transfer: 'Transferencia' };

// De qué barra o cocina sale cada línea: el POS ya tiene las categorías con
// su despacho, así que la comanda se separa sin preguntarle nada al servidor.
const dispatchOf = (productId) => {
    const product = pos.products.find((item) => item.id === productId);
    const category = pos.categories.find((cat) => cat.id === product?.category_id);

    return category?.dispatch === 'kitchen' ? 'Cocina' : 'Barra';
};

// Una comanda por destino: el papel de cocina no lleva las cervezas.
const grupos = computed(() => {
    const porDestino = new Map();

    for (const line of props.ticket.lines ?? []) {
        const destino = dispatchOf(line.product_id);
        if (! porDestino.has(destino)) porDestino.set(destino, []);
        porDestino.get(destino).push(line);
    }

    return [...porDestino.entries()].map(([destino, lines]) => ({ destino, lines }));
});

const cantidad = (n) => Number.isInteger(n) ? String(n) : String(n).replace('.', ',');
</script>

<template>
    <div class="ticket-print" :class="'ticket-' + kind">
        <header class="tp-head">
            <strong class="tp-title">{{ kind === 'comanda' ? 'COMANDA' : 'RECIBO' }}</strong>
            <p v-if="ticket.unit_name" class="tp-unit">{{ ticket.unit_name }}</p>
            <p class="tp-meta">
                <span v-if="number">Orden {{ number }}</span>
                <span v-else class="tp-nonumber">Sin número todavía · se asigna al sincronizar</span>
            </p>
            <p class="tp-meta">{{ fecha }}</p>
            <p v-if="ticket.cashier" class="tp-meta">Atendió: {{ ticket.cashier }}</p>
        </header>

        <p v-if="ticket.customer_name" class="tp-customer">
            <span class="tp-customer-label">Para</span>
            <strong>{{ ticket.customer_name }}</strong>
        </p>

        <!-- Comanda: sin precios y separada por destino -->
        <template v-if="kind === 'comanda'">
            <section v-for="grupo in grupos" :key="grupo.destino" class="tp-group">
                <p class="tp-group-title">{{ grupo.destino }}</p>
                <div v-for="(line, i) in grupo.lines" :key="i" class="tp-row tp-row-big">
                    <span class="tp-qty">{{ cantidad(line.quantity) }}×</span>
                    <span class="tp-name">
                        {{ line.product_name }}
                        <!-- La nota va DEBAJO y en negrita: es lo único de esta
                             hoja que cambia lo que hay que hacer. -->
                        <em v-if="line.notes" class="tp-note">{{ line.notes }}</em>
                    </span>
                </div>
            </section>
        </template>

        <!-- Recibo: lo que el cliente se lleva -->
        <template v-else>
            <div class="tp-lines">
                <div v-for="(line, i) in ticket.lines ?? []" :key="i" class="tp-row">
                    <span class="tp-qty">{{ cantidad(line.quantity) }}×</span>
                    <span class="tp-name">
                        {{ line.product_name }}
                        <em v-if="line.notes" class="tp-note">{{ line.notes }}</em>
                    </span>
                    <span class="tp-amount">{{ money(line.total_cents) }}</span>
                </div>
            </div>

            <div class="tp-totals">
                <div class="tp-total-row"><span>Subtotal</span><span>{{ money(ticket.subtotal ?? ticket.subtotal_cents ?? 0) }}</span></div>
                <div class="tp-total-row">
                    <span>{{ pos.itbisMode === 'added' ? 'ITBIS 18 %' : 'ITBIS incluido' }}</span>
                    <span>{{ money(ticket.itbis ?? ticket.itbis_cents ?? 0) }}</span>
                </div>
                <div v-if="(ticket.tip ?? ticket.tip_cents ?? 0) > 0" class="tp-total-row">
                    <span>Propina legal 10 %</span><span>{{ money(ticket.tip ?? ticket.tip_cents) }}</span>
                </div>
                <div class="tp-total-row tp-grand">
                    <span>TOTAL</span><span>{{ money(ticket.total ?? ticket.total_cents ?? 0) }}</span>
                </div>
            </div>

            <div v-if="ticket.method" class="tp-pay">
                <div class="tp-total-row"><span>{{ metodo[ticket.method] ?? ticket.method }}</span><span>{{ money(ticket.total ?? ticket.total_cents ?? 0) }}</span></div>
                <div v-if="ticket.method === 'cash' && ticket.change_cents > 0" class="tp-total-row">
                    <span>Recibido</span><span>{{ money(ticket.tendered_cents) }}</span>
                </div>
                <div v-if="ticket.method === 'cash' && ticket.change_cents > 0" class="tp-total-row">
                    <span>Vuelto</span><span>{{ money(ticket.change_cents) }}</span>
                </div>
            </div>

            <p class="tp-foot">¡Gracias por su visita!</p>
            <p class="tp-legal">Este documento no es un comprobante fiscal válido para crédito.</p>
        </template>
    </div>
</template>

<style>
/*
 * Sin scoped: las reglas @page y la de ocultar el resto tienen que alcanzar
 * al documento entero.
 *
 * El ticket se teletransporta a <body> para que la regla de abajo lo pueda
 * distinguir de todo lo demás. En PANTALLA vive fuera del viewport en vez
 * de con display:none, porque un elemento oculto así tampoco se imprime.
 */
.print-root { position: absolute; left: -10000px; top: 0; }

.ticket-print {
    width: 72mm; padding: 4mm 3mm;
    background: #fff; color: #000;
    font-family: 'Courier New', ui-monospace, monospace;
    font-size: 11px; line-height: 1.45;
}

.tp-head { text-align: center; border-bottom: 1px dashed #000; padding-bottom: 2mm; margin-bottom: 2mm; }
.tp-title { font-size: 15px; letter-spacing: .18em; }
.tp-unit { font-size: 12px; font-weight: 700; margin-top: 1mm; }
.tp-meta { font-size: 10px; margin-top: .5mm; }
.tp-nonumber { font-style: italic; }

.tp-customer {
    text-align: center; border: 1px solid #000; padding: 1.5mm;
    margin-bottom: 2mm; font-size: 13px;
}
.tp-customer-label { display: block; font-size: 9px; letter-spacing: .12em; }

.tp-group { margin-bottom: 3mm; }
.tp-group-title {
    font-weight: 700; font-size: 12px; letter-spacing: .1em;
    border-bottom: 1px solid #000; margin-bottom: 1.5mm; padding-bottom: .5mm;
}

.tp-row { display: flex; gap: 2mm; align-items: baseline; margin-bottom: 1mm; }
.tp-row-big { font-size: 14px; font-weight: 700; margin-bottom: 2mm; }
.tp-qty { min-width: 8mm; font-weight: 700; }
.tp-name { flex: 1; }
.tp-note { display: block; font-style: normal; font-weight: 700; text-transform: uppercase; }
.tp-amount { text-align: right; white-space: nowrap; }

.tp-lines { border-bottom: 1px dashed #000; padding-bottom: 2mm; margin-bottom: 2mm; }
.tp-totals { border-bottom: 1px dashed #000; padding-bottom: 2mm; margin-bottom: 2mm; }
.tp-total-row { display: flex; justify-content: space-between; gap: 3mm; }
.tp-grand { font-size: 14px; font-weight: 700; margin-top: 1.5mm; }
.tp-pay { margin-bottom: 3mm; }
.tp-foot { text-align: center; font-size: 12px; margin-top: 3mm; }
.tp-legal { text-align: center; font-size: 9px; margin-top: 1.5mm; }

@media print {
    /* Rollo de 80mm, que es lo que hay en la barra. */
    @page { size: 80mm auto; margin: 0; }

    /* Solo el ticket va al papel: el resto del POS desaparece. */
    body > *:not(.print-root) { display: none !important; }

    .print-root { position: static; left: auto; }
    .ticket-print { width: auto; padding: 3mm; }
}
</style>
