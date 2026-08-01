<script setup>
import { computed, ref } from 'vue';
import { usePos } from '../store';
import { money, toCents } from '../money';

const pos = usePos();
const category = ref(null);
const paying = ref(false);
const method = ref('cash');
const tendered = ref('');
const counting = ref(false);
const counted = ref('');

const visibleProducts = computed(() =>
    pos.products.filter((product) => category.value === null || product.category_id === category.value));

const submitting = ref(false);
const tenderedCents = computed(() => toCents(tendered.value));
const change = computed(() => tenderedCents.value - pos.totals.total);
const canCharge = computed(() => method.value === 'cash'
    ? tenderedCents.value >= pos.totals.total
    : true);

function openPayment() {
    // El modal abre limpio: nada del cobro anterior se hereda.
    method.value = 'cash';
    tendered.value = '';
    paying.value = true;
}

async function confirm() {
    // Un solo cobro por tap: el guard mata el doble toque, que crearia una
    // venta DUPLICADA real (dos referencias distintas que la idempotencia
    // del servidor no puede unir).
    if (submitting.value) return;
    submitting.value = true;
    try {
        // Tarjeta y transferencia van por el monto exacto; el vuelto es
        // cosa del efectivo. El cobro queda local y sincroniza solo.
        await pos.charge(method.value, method.value === 'cash' ? tenderedCents.value : pos.totals.total);
        paying.value = false;
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="sale">
        <nav class="cats">
            <button :class="{ active: category === null }" @click="category = null">Todo</button>
            <button v-for="cat in pos.categories" :key="cat.id"
                :class="{ active: category === cat.id }" @click="category = cat.id">{{ cat.name }}</button>
        </nav>

        <main class="grid">
            <button v-for="product in visibleProducts" :key="product.id" class="product"
                @click="pos.addToCart(product)">
                <span>{{ product.name }}</span>
                <strong>{{ money(product.price_cents) }}</strong>
            </button>
        </main>

        <aside class="cart">
            <div v-if="pos.cart.length === 0" class="empty">Toca un producto para empezar la orden.</div>
            <div v-for="line in pos.cart" :key="line.product_id" class="line">
                <button class="minus" @click="pos.removeFromCart(line.product_id)">−</button>
                <span class="qty">{{ line.quantity }}×</span>
                <span class="name">{{ line.name }}</span>
                <span>{{ money(line.price_cents * line.quantity) }}</span>
            </div>

            <label class="tip"><input v-model="pos.withTip" type="checkbox"> Propina legal 10 %</label>

            <div class="totals">
                <span>ITBIS incluido: {{ money(pos.totals.itbis) }}</span>
                <span v-if="pos.totals.tip">Propina: {{ money(pos.totals.tip) }}</span>
                <strong>Total: {{ money(pos.totals.total) }}</strong>
            </div>

            <button class="primary" :disabled="pos.cart.length === 0 || pos.closingTill" @click="openPayment()">Cobrar</button>
            <button class="ghost-wide" @click="counting = true">Cerrar caja</button>
        </aside>

        <div v-if="paying" class="modal" @click.self="paying = false">
            <div class="sheet">
                <h2>Cobrar {{ money(pos.totals.total) }}</h2>
                <label class="field"><span>Metodo</span>
                    <select v-model="method">
                        <option value="cash">Efectivo</option>
                        <option value="card">Tarjeta</option>
                        <option value="transfer">Transferencia</option>
                    </select>
                </label>
                <label v-if="method === 'cash'" class="field"><span>Recibido (RD$)</span>
                    <input v-model="tendered" type="text" inputmode="decimal">
                </label>
                <p v-if="method === 'cash' && tendered !== '' && change >= 0" class="change">Vuelto: {{ money(change) }}</p>
                <p v-else-if="method === 'cash' && tendered !== ''" class="short">Faltan {{ money(-change) }}</p>
                <button class="primary" :disabled="!canCharge || submitting" @click="confirm()">
                    {{ submitting ? 'Cobrando...' : 'Confirmar cobro' }}
                </button>
            </div>
        </div>

        <div v-if="counting" class="modal" @click.self="counting = false">
            <div class="sheet">
                <h2>Cerrar caja</h2>
                <label class="field"><span>Efectivo contado (RD$)</span>
                    <input v-model="counted" type="text" inputmode="decimal">
                </label>
                <p class="review-note">Se cierra contra {{ money(toCents(counted)) }}. Irreversible desde el POS.</p>
                <button class="primary" :disabled="pos.busy || counted === ''"
                    @click="pos.closeTill(toCents(counted)); counting = false">
                    Cerrar contra lo contado
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sale { flex: 1; display: grid; grid-template-columns: 1fr 300px; grid-template-rows: auto 1fr; gap: 0; min-height: 0; }
.cats { grid-column: 1 / 2; display: flex; gap: .5rem; padding: .6rem; overflow-x: auto; }
.cats button { border: 1px solid #334155; background: #1e293b; color: #cbd5e1; border-radius: 999px; padding: .4rem .9rem; white-space: nowrap; }
.cats button.active { background: #0284c7; color: white; border-color: #0284c7; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: .6rem; padding: .6rem; align-content: start; overflow-y: auto; }
.product { display: flex; flex-direction: column; gap: .4rem; padding: .8rem; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; border-radius: .8rem; text-align: left; min-height: 84px; }
.product strong { color: #38bdf8; }
.cart { grid-row: 1 / 3; grid-column: 2; background: #1e293b; padding: .8rem; display: flex; flex-direction: column; gap: .5rem; }
.line { display: flex; align-items: center; gap: .5rem; }
.line .name { flex: 1; }
.minus { width: 1.8rem; height: 1.8rem; border-radius: .4rem; border: 1px solid #475569; background: none; color: #f87171; }
.empty { color: #64748b; padding: 1rem 0; }
.tip { margin-top: auto; color: #cbd5e1; }
.totals { display: flex; flex-direction: column; gap: .15rem; color: #94a3b8; }
.totals strong { color: #e2e8f0; font-size: 1.3rem; }
.ghost-wide { background: none; border: 1px solid #475569; color: #94a3b8; border-radius: .6rem; padding: .6rem; }
.modal { position: fixed; inset: 0; background: rgb(0 0 0 / .6); display: grid; place-items: center; }
.sheet { background: #1e293b; border-radius: 1rem; padding: 1.2rem; width: min(420px, 92vw); }
.change { color: #86efac; margin-bottom: .8rem; }
.short { color: #f87171; margin-bottom: .8rem; }
.review-note { color: #94a3b8; font-size: .85rem; margin-bottom: .8rem; }
@media (max-width: 720px) {
    .sale { grid-template-columns: 1fr; grid-template-rows: auto 1fr auto; }
    .cart { grid-row: auto; grid-column: 1; }
}
</style>
