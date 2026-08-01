<script setup>
import { computed, ref } from 'vue';
import { usePos } from '../store';
import { money, toCents } from '../money';

const pos = usePos();
const category = ref(null);
const search = ref('');
const paying = ref(false);
const method = ref('cash');
const tendered = ref('');
const counting = ref(false);
const counted = ref('');

const visibleProducts = computed(() => pos.products.filter((product) =>
    (category.value === null || product.category_id === category.value)
    && (search.value.trim() === ''
        || product.name.toLowerCase().includes(search.value.trim().toLowerCase()))));

const itemCount = computed(() => pos.cart.reduce((sum, line) => sum + line.quantity, 0));

const submitting = ref(false);
const tenderedCents = computed(() => toCents(tendered.value));
const change = computed(() => tenderedCents.value - pos.totals.total);
const canCharge = computed(() => method.value === 'cash'
    ? tenderedCents.value >= pos.totals.total
    : true);

const billetes = [200, 500, 1000, 2000, 5000];

const methods = [
    { value: 'cash', label: 'Efectivo' },
    { value: 'card', label: 'Tarjeta' },
    { value: 'transfer', label: 'Transf.' },
];

// Sumar una unidad desde el ticket: la linea guarda todo lo que addToCart
// necesita del producto (precio, exencion), asi que no hay que buscarlo.
function addOne(line) {
    pos.addToCart({
        id: line.product_id,
        name: line.name,
        price_cents: line.price_cents,
        itbis_exempt: line.itbis_exempt,
    });
}

function openPayment() {
    // El modal abre limpio: nada del cobro anterior se hereda.
    method.value = 'cash';
    tendered.value = '';
    paying.value = true;
}

function exactAmount() {
    tendered.value = (pos.totals.total / 100).toFixed(2);
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
        <div class="catalog">
            <div class="catalog-tools">
                <div class="search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input v-model="search" type="text" placeholder="Buscar en el menu..." autocapitalize="none">
                </div>
                <nav class="cats">
                    <button :class="{ active: category === null }" @click="category = null">Todo</button>
                    <button v-for="cat in pos.categories" :key="cat.id"
                        :class="{ active: category === cat.id }" @click="category = cat.id">{{ cat.name }}</button>
                </nav>
            </div>

            <main class="grid">
                <button v-for="product in visibleProducts" :key="product.id" class="product"
                    @click="pos.addToCart(product)">
                    <span class="product-avatar">{{ product.name.slice(0, 1).toUpperCase() }}</span>
                    <span class="product-name">{{ product.name }}</span>
                    <strong class="product-price money">{{ money(product.price_cents) }}</strong>
                </button>
                <p v-if="visibleProducts.length === 0" class="grid-empty">Nada coincide con la busqueda.</p>
            </main>
        </div>

        <aside class="ticket">
            <div class="ticket-head">
                <h2>Orden</h2>
                <span v-if="itemCount > 0" class="ticket-count">{{ itemCount }} articulo(s)</span>
            </div>

            <div class="ticket-lines">
                <div v-if="pos.cart.length === 0" class="ticket-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/></svg>
                    <p>Toca un producto<br>para empezar la orden.</p>
                </div>
                <div v-for="line in pos.cart" :key="line.product_id" class="line">
                    <div class="line-info">
                        <span class="line-name">{{ line.name }}</span>
                        <span class="line-unit money">{{ money(line.price_cents) }} c/u</span>
                    </div>
                    <div class="stepper">
                        <button class="step" @click="pos.removeFromCart(line.product_id)">−</button>
                        <span class="qty">{{ line.quantity }}</span>
                        <button class="step step-add" @click="addOne(line)">+</button>
                    </div>
                    <span class="line-total money">{{ money(line.price_cents * line.quantity) }}</span>
                </div>
            </div>

            <div class="ticket-foot">
                <label class="tip-row">
                    <span>Propina legal 10 %</span>
                    <span class="switch" :class="{ on: pos.withTip }">
                        <input v-model="pos.withTip" type="checkbox">
                        <span class="knob"></span>
                    </span>
                </label>

                <div class="totals">
                    <div class="trow"><span>Subtotal</span><span class="money">{{ money(pos.totals.subtotal) }}</span></div>
                    <div class="trow"><span>ITBIS incluido</span><span class="money">{{ money(pos.totals.itbis) }}</span></div>
                    <div v-if="pos.totals.tip" class="trow"><span>Propina</span><span class="money">{{ money(pos.totals.tip) }}</span></div>
                    <div class="trow trow-total"><span>Total</span><strong class="money">{{ money(pos.totals.total) }}</strong></div>
                </div>

                <button class="btn-primary" :disabled="pos.cart.length === 0 || pos.closingTill" @click="openPayment()">
                    Cobrar<span v-if="pos.cart.length > 0" class="money"> · {{ money(pos.totals.total) }}</span>
                </button>
                <button class="btn-close-till" @click="counting = true">Cerrar caja</button>
            </div>
        </aside>

        <div v-if="paying" class="overlay" @click.self="paying = false">
            <div class="sheet">
                <div class="sheet-head">
                    <h2>Cobro</h2>
                    <button class="icon-btn" @click="paying = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <p class="pay-total money">{{ money(pos.totals.total) }}</p>

                <div class="segmented">
                    <button v-for="option in methods" :key="option.value"
                        :class="{ active: method === option.value }" @click="method = option.value">
                        {{ option.label }}
                    </button>
                </div>

                <template v-if="method === 'cash'">
                    <label class="field"><span>Recibido (RD$)</span>
                        <input v-model="tendered" type="text" inputmode="decimal" placeholder="0.00" class="pay-input money">
                    </label>
                    <div class="chips pay-chips">
                        <button type="button" class="chip-btn" @click="exactAmount()">Exacto</button>
                        <button v-for="billete in billetes" :key="billete" type="button" class="chip-btn"
                            @click="tendered = String(billete)">
                            {{ billete.toLocaleString('es-DO') }}
                        </button>
                    </div>
                    <p v-if="tendered !== '' && change >= 0" class="change money">Vuelto: {{ money(change) }}</p>
                    <p v-else-if="tendered !== ''" class="short money">Faltan {{ money(-change) }}</p>
                </template>

                <button class="btn-primary" :disabled="!canCharge || submitting" @click="confirm()">
                    {{ submitting ? 'Cobrando...' : 'Confirmar cobro' }}
                </button>
            </div>
        </div>

        <div v-if="counting" class="overlay" @click.self="counting = false">
            <div class="sheet">
                <div class="sheet-head">
                    <h2>Cerrar caja</h2>
                    <button class="icon-btn" @click="counting = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>
                <label class="field"><span>Efectivo contado (RD$)</span>
                    <input v-model="counted" type="text" inputmode="decimal" placeholder="0.00" class="pay-input money">
                </label>
                <p class="close-note">Se cierra contra <strong class="money">{{ money(toCents(counted)) }}</strong>. Irreversible desde el POS.</p>
                <button class="btn-primary" :disabled="pos.busy || counted === ''"
                    @click="pos.closeTill(toCents(counted)); counting = false">
                    Cerrar contra lo contado
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sale { flex: 1; display: grid; grid-template-columns: 1fr 340px; min-height: 0; }

/* ---------- Catalogo ---------- */
.catalog { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
.catalog-tools {
    display: flex; flex-direction: column; gap: .55rem;
    padding: .75rem .9rem .55rem;
}
.search { position: relative; }
.search svg {
    position: absolute; left: .8rem; top: 50%; transform: translateY(-50%);
    width: 16px; height: 16px; color: var(--muted); pointer-events: none;
}
.search input { padding-left: 2.4rem; background: var(--panel); }
.cats { display: flex; gap: .45rem; overflow-x: auto; padding-bottom: .2rem; scrollbar-width: none; }
.cats::-webkit-scrollbar { display: none; }
.cats button {
    border: 1px solid var(--line-strong); background: var(--panel); color: var(--muted);
    border-radius: 999px; padding: .45rem 1rem; white-space: nowrap;
    font-size: .84rem; font-weight: 600; transition: all .15s; flex-shrink: 0;
}
.cats button.active {
    background: linear-gradient(135deg, #0ea5e9, #0369a1); color: white; border-color: transparent;
    box-shadow: 0 4px 14px rgba(14, 165, 233, .3);
}

.grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
    gap: .6rem; padding: .35rem .9rem 1rem; align-content: start; overflow-y: auto;
}
.product {
    display: flex; flex-direction: column; align-items: flex-start; gap: .45rem;
    padding: .8rem; min-height: 108px;
    border: 1px solid var(--line); background: var(--panel); color: var(--text);
    border-radius: 14px; text-align: left;
    transition: transform .1s, border-color .15s, background .15s;
}
.product:hover { border-color: var(--line-strong); background: var(--panel-2); }
.product:active { transform: scale(.97); }
.product-avatar {
    display: grid; place-items: center; width: 30px; height: 30px; border-radius: 9px;
    background: rgba(14, 165, 233, .14); color: #38bdf8; font-weight: 700; font-size: .85rem;
}
.product-name { font-size: .86rem; line-height: 1.25; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.product-price { margin-top: auto; color: #38bdf8; font-size: .9rem; }
.grid-empty { grid-column: 1 / -1; color: var(--muted); padding: 2rem 0; text-align: center; font-size: .9rem; }

/* ---------- Ticket ---------- */
.ticket {
    display: flex; flex-direction: column; min-height: 0;
    background: var(--panel); border-left: 1px solid var(--line);
}
.ticket-head {
    display: flex; align-items: baseline; justify-content: space-between;
    padding: .9rem 1rem .6rem;
}
.ticket-head h2 { font-size: 1rem; }
.ticket-count { font-size: .76rem; color: var(--muted); }

.ticket-lines { flex: 1; overflow-y: auto; padding: 0 1rem; }
.ticket-empty {
    display: flex; flex-direction: column; align-items: center; gap: .7rem;
    color: var(--muted); text-align: center; padding: 2.4rem 0; font-size: .86rem;
}
.ticket-empty svg { width: 34px; height: 34px; opacity: .5; }

.line {
    display: flex; align-items: center; gap: .6rem;
    padding: .6rem 0; border-bottom: 1px solid var(--line);
}
.line-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .1rem; }
.line-name { font-size: .86rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.line-unit { font-size: .72rem; color: var(--muted); }
.stepper {
    display: flex; align-items: center; gap: .1rem;
    background: var(--panel-2); border: 1px solid var(--line); border-radius: 10px;
}
.step {
    width: 30px; height: 30px; display: grid; place-items: center;
    font-size: 1.05rem; color: var(--muted); border-radius: 9px; transition: all .12s;
}
.step:hover { color: var(--bad); background: rgba(248, 113, 113, .1); }
.step-add:hover { color: var(--ok); background: rgba(52, 211, 153, .1); }
.qty { min-width: 1.5rem; text-align: center; font-weight: 700; font-size: .88rem; }
.line-total { font-size: .88rem; font-weight: 600; min-width: 4.6rem; text-align: right; }

.ticket-foot { padding: .8rem 1rem 1rem; border-top: 1px solid var(--line); display: flex; flex-direction: column; gap: .7rem; }

.tip-row {
    display: flex; align-items: center; justify-content: space-between;
    font-size: .86rem; color: var(--text); cursor: pointer;
}
.switch { position: relative; width: 42px; height: 24px; display: inline-block; }
.switch input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.switch .knob {
    position: absolute; inset: 0; border-radius: 999px; background: var(--panel-2);
    border: 1px solid var(--line-strong); transition: background .15s, border-color .15s;
    pointer-events: none;
}
.switch .knob::after {
    content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px;
    border-radius: 999px; background: var(--muted); transition: transform .15s, background .15s;
}
.switch.on .knob { background: rgba(14, 165, 233, .25); border-color: var(--accent); }
.switch.on .knob::after { transform: translateX(18px); background: var(--accent-2, #38bdf8); }

.totals { display: flex; flex-direction: column; gap: .3rem; }
.trow { display: flex; justify-content: space-between; font-size: .82rem; color: var(--muted); }
.trow-total { margin-top: .2rem; padding-top: .55rem; border-top: 1px dashed var(--line-strong); color: var(--text); align-items: baseline; }
.trow-total strong { font-size: 1.45rem; letter-spacing: -.01em; }

.btn-close-till {
    border: 1px solid var(--line); border-radius: 12px; padding: .6rem;
    color: var(--muted); font-size: .84rem; transition: all .15s;
}
.btn-close-till:hover { color: var(--text); border-color: var(--line-strong); }

/* ---------- Cobro ---------- */
.pay-total { font-size: 2.1rem; font-weight: 800; letter-spacing: -.02em; margin-bottom: 1rem; }
.segmented {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: .3rem;
    background: var(--panel-2); border: 1px solid var(--line); border-radius: 12px;
    padding: .3rem; margin-bottom: 1rem;
}
.segmented button {
    padding: .6rem .4rem; border-radius: 9px; font-size: .86rem; font-weight: 600;
    color: var(--muted); transition: all .15s;
}
.segmented button.active { background: linear-gradient(135deg, #0ea5e9, #0369a1); color: white; box-shadow: 0 4px 12px rgba(14, 165, 233, .3); }
.pay-input { font-size: 1.25rem; font-weight: 700; }
.pay-chips { margin: -.3rem 0 .9rem; }
.change { color: var(--ok); font-size: 1.05rem; font-weight: 700; margin-bottom: .9rem; }
.short { color: var(--bad); font-size: 1rem; font-weight: 600; margin-bottom: .9rem; }
.close-note { color: var(--muted); font-size: .84rem; margin-bottom: 1rem; }
.close-note strong { color: var(--text); }

/* ---------- Movil ---------- */
@media (max-width: 760px) {
    .sale { grid-template-columns: 1fr; grid-template-rows: 1fr auto; }
    .ticket { border-left: 0; border-top: 1px solid var(--line-strong); max-height: 46vh; }
    .grid { grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); }
    .product { min-height: 96px; }
}
</style>
