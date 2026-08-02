<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePos } from '../store';
import { money, toCents } from '../money';

const pos = usePos();

const refunding = ref(null);   // la venta que se esta devolviendo
const amount = ref('');
const reason = ref('');

const motivos = ['Producto equivocado', 'Cliente se arrepintio', 'Cobro duplicado', 'Producto en mal estado'];

const buscar = ref('');
const visibles = computed(() => {
    const q = buscar.value.trim().toLowerCase();
    return q === '' ? pos.sales : pos.sales.filter((s) => s.number.toLowerCase().includes(q));
});

const pendiente = computed(() => refunding.value
    ? refunding.value.total_cents - refunding.value.refunded_cents
    : 0);

const amountCents = computed(() => toCents(amount.value));

const puedeDevolver = computed(() => amountCents.value > 0
    && amountCents.value <= pendiente.value
    && reason.value.trim() !== '');

function abrir(sale) {
    refunding.value = sale;
    // Lo habitual es devolver todo lo que queda: se ofrece cargado.
    amount.value = ((sale.total_cents - sale.refunded_cents) / 100).toFixed(2);
    reason.value = '';
}

async function confirmar() {
    if (!puedeDevolver.value) return;
    const ok = await pos.refundSale(refunding.value.id, amountCents.value, reason.value.trim());
    if (ok) refunding.value = null;
}

onMounted(() => pos.loadSales());
</script>

<template>
    <div class="sales">
        <div class="sales-head">
            <div>
                <h1>Ventas del turno</h1>
                <p class="sales-sub">Las cobradas en esta caja. El reembolso sale de tu gaveta y queda registrado.</p>
            </div>
            <button class="btn-soft" :disabled="pos.loadingSales" @click="pos.loadSales()">
                {{ pos.loadingSales ? 'Cargando...' : 'Actualizar' }}
            </button>
        </div>

        <div class="search-row">
            <input v-model="buscar" type="text" placeholder="Buscar por numero (P0041)" autocapitalize="characters">
        </div>

        <div v-if="!pos.salesLoaded && !pos.loadingSales" class="sales-empty">
            <p>No se pudo cargar la lista: necesita senal.</p>
            <button class="btn-soft" @click="pos.loadSales()">Reintentar</button>
        </div>
        <div v-else-if="visibles.length === 0 && !pos.loadingSales" class="sales-empty">
            <p>{{ buscar.trim() ? 'Ninguna venta con ese numero.' : 'Sin ventas en esta caja todavia.' }}</p>
        </div>

        <ul class="sales-list">
            <li v-for="sale in visibles" :key="sale.id" class="sale-row">
                <div class="sale-main">
                    <span class="sale-number">{{ sale.number }}</span>
                    <span class="sale-meta">
                        <span class="state-chip" :class="'state-' + sale.status">
                            {{ sale.status === 'paid' ? 'Cobrada' : (sale.status === 'void' ? 'Anulada' : 'Abierta') }}
                        </span>
                        <template v-if="sale.method">{{ sale.method === 'cash' ? 'Efectivo' : (sale.method === 'card' ? 'Tarjeta' : 'Transferencia') }}</template>
                    </span>
                </div>

                <div class="sale-amounts">
                    <span class="sale-total money">{{ money(sale.total_cents) }}</span>
                    <span v-if="sale.refunded_cents > 0" class="sale-refunded money">
                        −{{ money(sale.refunded_cents) }} devuelto
                    </span>
                </div>

                <button v-if="pos.canRefund && sale.status === 'paid' && sale.refunded_cents < sale.total_cents"
                    class="btn-soft btn-refund" @click="abrir(sale)">
                    Reembolsar
                </button>
            </li>
        </ul>

        <div v-if="refunding" class="overlay" @click.self="refunding = null">
            <div class="sheet">
                <div class="sheet-head">
                    <h2>Reembolsar {{ refunding.number }}</h2>
                    <button class="icon-btn" @click="refunding = null">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <p class="refund-available money">Disponible: {{ money(pendiente) }}</p>

                <label class="field"><span>Monto a devolver (RD$)</span>
                    <input v-model="amount" type="text" inputmode="decimal" class="money">
                </label>

                <label class="field"><span>Motivo</span>
                    <input v-model="reason" type="text" maxlength="255" placeholder="Por que se devuelve">
                </label>

                <div class="chips refund-chips">
                    <button v-for="motivo in motivos" :key="motivo" type="button" class="chip-btn" @click="reason = motivo">
                        {{ motivo }}
                    </button>
                </div>

                <p v-if="amount !== '' && amountCents > pendiente" class="refund-warn">
                    No se puede devolver mas de lo disponible.
                </p>

                <p class="refund-note">
                    La venta no se borra: queda con su reembolso anotado. El inventario no se repone.
                </p>

                <button class="btn-primary" :disabled="!puedeDevolver || pos.busy" @click="confirmar()">
                    {{ pos.busy ? 'Devolviendo...' : 'Confirmar reembolso' }}
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sales { flex: 1; overflow-y: auto; padding: 1rem; max-width: 720px; width: 100%; margin: 0 auto; }
.sales-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
h1 { font-size: 1.15rem; }
.sales-sub { color: var(--muted); font-size: .82rem; margin-top: .2rem; }
.sales-empty { color: var(--muted); text-align: center; padding: 3rem 0; font-size: .9rem; }

.sales-list { list-style: none; display: flex; flex-direction: column; }
.sale-row {
    display: flex; align-items: center; gap: .8rem;
    padding: .8rem .2rem; border-bottom: 1px solid var(--line);
}
.sale-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .15rem; }
.sale-number { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-weight: 700; font-size: .95rem; }
.sale-meta { font-size: .75rem; color: var(--muted); }
.sale-amounts { display: flex; flex-direction: column; align-items: flex-end; gap: .15rem; }
.sale-total { font-weight: 600; font-size: .92rem; }
.sale-refunded { font-size: .72rem; color: var(--warn); }
.btn-refund { flex-shrink: 0; }

.search-row { margin-bottom: .8rem; }
.state-chip { border-radius: 4px; padding: .1rem .4rem; font-size: .68rem; font-weight: 700; margin-right: .35rem; }
.state-paid { background: rgba(52, 211, 153, .12); color: #6ee7b7; }
.state-void { background: rgba(248, 113, 113, .12); color: #fca5a5; }
.state-open { background: rgba(251, 191, 36, .12); color: #fcd34d; }
.refund-warn { color: var(--bad); font-size: .82rem; margin-bottom: .8rem; }
.refund-available { color: var(--muted); font-size: .88rem; margin-bottom: 1rem; }
.refund-chips { margin: -.3rem 0 1rem; }
.refund-note { color: var(--muted); font-size: .78rem; margin-bottom: 1rem; line-height: 1.5; }
</style>
