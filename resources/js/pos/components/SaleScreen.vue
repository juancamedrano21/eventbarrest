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
const customer = ref('');
const done = ref(false);

const matchesSearch = (product) => search.value.trim() === ''
    || product.name.toLowerCase().includes(search.value.trim().toLowerCase());

const visibleProducts = computed(() => pos.products.filter((product) =>
    (category.value === null || product.category_id === category.value) && matchesSearch(product)));

// El contador de cada pestaña respeta la búsqueda: si el cajero escribe
// «moji», «Bebidas 1» le dice dónde está, en vez de prometerle veinte.
const countFor = (categoryId) => pos.products.filter((product) =>
    (categoryId === null || product.category_id === categoryId) && matchesSearch(product)).length;

const itemCount = computed(() => pos.cart.reduce((sum, line) => sum + line.quantity, 0));

// Cuántas unidades de este producto ya van en la orden: el botón de la
// tarjeta lo dice sin que haya que mirar el ticket. Se SUMAN las líneas,
// porque un producto anotado abre línea aparte y el contador tiene que
// seguir diciendo la verdad.
const inCart = (productId) => pos.cart
    .filter((line) => line.product_id === productId)
    .reduce((total, line) => total + line.quantity, 0);

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

// Sin foto, un bloque de color estable derivado del nombre: el mismo plato
// tiene siempre el mismo color, y eso se reconoce de un vistazo mejor que
// una silueta gris repetida veinte veces.
function tint(name) {
    let hash = 0;
    for (const char of name) hash = (hash * 31 + char.charCodeAt(0)) % 360;

    return `hsl(${hash} 46% 62%)`;
}

function initials(name) {
    return name.trim().split(/\s+/).slice(0, 2).map((word) => word[0]).join('').toUpperCase();
}

function addOne(line) {
    // Sumar sobre ESTA linea, no sobre la primera del producto: si esta
    // lleva «sin cebolla», el + tiene que dar otro sin cebolla.
    line.quantity += 1;
    pos.saveDraft();
}

// La nota que va a leer quien cocina. Se escribe con un teclado en pantalla
// pero con atajos primero: en una barra llena, «Sin cebolla» tiene que ser
// un toque, no doce.
const noting = ref(null);
const noteText = ref('');
const atajos = ['Sin cebolla', 'Sin picante', 'Para llevar', 'Bien cocido', 'Sin hielo'];

function openNote(line) {
    noting.value = line;
    noteText.value = line.notes ?? '';
}

function saveNote() {
    pos.setLineNote(noting.value.key, noteText.value);
    noting.value = null;
}

function openPayment() {
    // El modal abre limpio: nada del cobro anterior se hereda.
    method.value = 'cash';
    tendered.value = '';
    customer.value = '';
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
        await pos.charge(
            method.value,
            method.value === 'cash' ? tenderedCents.value : pos.totals.total,
            customer.value,
        );
        paying.value = false;
        // La venta cobrada se confirma en pantalla: sin esto el cajero se
        // queda sin saber si pasó, y el ticket vacío no es respuesta.
        if (pos.lastSale) done.value = true;
    } finally {
        submitting.value = false;
    }
}

// El ticket congelado de la ultima venta: el modal lo lee de un sitio, no
// de pos.lastSale.ticket repetido veinte veces en la plantilla.
const lastTicket = computed(() => pos.lastSale?.ticket ?? {});

const lastUnits = computed(() =>
    (lastTicket.value.lines ?? []).reduce((sum, line) => sum + line.quantity, 0));

const lastWhen = computed(() => new Intl.DateTimeFormat('es-DO', {
    hour: 'numeric', minute: '2-digit',
}).format(pos.lastSale?.created_at ? new Date(pos.lastSale.created_at) : new Date()));

const metodoLargo = {
    cash: 'Efectivo',
    card: 'Tarjeta',
    transfer: 'Transferencia',
};

function printFrom(kind) {
    if (! pos.lastSale) return;
    pos.printTicket(pos.lastSale.ticket, kind, pos.lastSale.number);
}
</script>

<template>
    <div class="sale">
        <div class="catalog">
            <div class="catalog-tools">
                <nav class="cats">
                    <button :class="{ active: category === null }" @click="category = null">
                        Todo <span class="cat-count">{{ countFor(null) }}</span>
                    </button>
                    <button v-for="cat in pos.categories" :key="cat.id"
                        :class="{ active: category === cat.id }" @click="category = cat.id">
                        {{ cat.name }} <span class="cat-count">{{ countFor(cat.id) }}</span>
                    </button>
                </nav>
                <div class="search">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input v-model="search" type="text" placeholder="Buscar en el menu..." autocapitalize="none">
                </div>
            </div>

            <main class="grid">
                <article v-for="product in visibleProducts" :key="product.id" class="product"
                    :class="{ 'is-off': product.active === false }">
                    <div class="thumb">
                        <img v-if="product.image_url" :src="product.image_url" :alt="product.name" loading="lazy">
                        <span v-else class="thumb-fallback" :style="{ background: tint(product.name) }">
                            {{ initials(product.name) }}
                        </span>
                        <span class="badge" :class="product.active === false ? 'badge-off' : 'badge-on'">
                            <span class="badge-dot"></span>
                            {{ product.active === false ? 'Agotado' : 'Disponible' }}
                        </span>
                    </div>

                    <div class="product-body">
                        <h3 class="product-name">{{ product.name }}</h3>
                        <strong class="product-price money">{{ money(product.price_cents) }}</strong>
                    </div>

                    <button v-if="product.active === false" class="product-btn product-btn-off" disabled>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                        No disponible
                    </button>
                    <button v-else-if="inCart(product.id) > 0" class="product-btn product-btn-more"
                        @click="pos.addToCart(product)">
                        Añadir más ({{ inCart(product.id) }})
                    </button>
                    <button v-else class="product-btn" @click="pos.addToCart(product)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                        Añadir
                    </button>
                </article>
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
                <div v-for="line in pos.cart" :key="line.key" class="line">
                    <span class="line-thumb">
                        <img v-if="line.image_url" :src="line.image_url" :alt="line.name" loading="lazy">
                        <span v-else class="thumb-fallback" :style="{ background: tint(line.name) }">
                            {{ initials(line.name) }}
                        </span>
                    </span>
                    <div class="line-info">
                        <span class="line-name">{{ line.name }}</span>
                        <span class="line-unit money">{{ money(line.price_cents) }} c/u</span>
                        <button class="line-note" :class="{ set: line.notes }" @click="openNote(line)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            <span>{{ line.notes ?? 'Nota' }}</span>
                        </button>
                        <strong class="line-total money">{{ money(line.price_cents * line.quantity) }}</strong>
                    </div>
                    <div class="line-side">
                        <div class="stepper">
                            <button class="step" @click="pos.removeFromCart(line.key)">−</button>
                            <span class="qty">{{ line.quantity }}</span>
                            <button class="step step-add" @click="addOne(line)">+</button>
                        </div>
                        <button class="line-drop" title="Quitar de la orden" @click="pos.dropLine(line.key)">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
                        </button>
                    </div>
                </div>
            </div>

            <div class="ticket-foot">
                <label class="tip-row">
                    <span>Propina legal 10 %</span>
                    <span class="switch" :class="{ on: pos.withTip }">
                        <input v-model="pos.withTip" type="checkbox" @change="pos.saveDraft()">
                        <span class="knob"></span>
                    </span>
                </label>

                <div class="totals">
                    <div class="trow"><span>Subtotal</span><span class="money">{{ money(pos.totals.subtotal) }}</span></div>
                    <div class="trow"><span>{{ pos.itbisMode === 'added' ? 'ITBIS 18 %' : 'ITBIS incluido' }}</span><span class="money">{{ money(pos.totals.itbis) }}</span></div>
                    <div v-if="pos.totals.tip" class="trow"><span>Propina</span><span class="money">{{ money(pos.totals.tip) }}</span></div>
                    <div class="trow trow-total"><span>Total</span><strong class="money">{{ money(pos.totals.total) }}</strong></div>
                </div>

                <button class="btn-primary" :disabled="pos.cart.length === 0 || pos.closingTill" @click="openPayment()">
                    Cobrar<span v-if="pos.cart.length > 0" class="money"> · {{ money(pos.totals.total) }}</span>
                </button>
            </div>
        </aside>

        <div v-if="noting" class="overlay" @click.self="noting = null">
            <div class="sheet">
                <div class="sheet-head">
                    <h2>{{ noting.name }}</h2>
                    <button class="icon-btn" @click="noting = null">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <p class="note-hint">Lo que va a leer quien lo prepara.</p>

                <div class="chips">
                    <button v-for="atajo in atajos" :key="atajo" type="button" class="chip-btn"
                        @click="noteText = atajo">{{ atajo }}</button>
                </div>

                <label class="field"><span>Nota</span>
                    <input v-model="noteText" type="text" maxlength="120" placeholder="Sin cebolla"
                        autocapitalize="sentences" autocomplete="off" @keyup.enter="saveNote()">
                </label>

                <button class="btn-primary" @click="saveNote()">Guardar</button>
                <button v-if="noting.notes" class="btn-soft" @click="noteText = ''; saveNote()">Quitar la nota</button>
            </div>
        </div>

        <div v-if="paying" class="overlay" @click.self="paying = false">
            <div class="sheet">
                <div class="sheet-head">
                    <h2>Cobro</h2>
                    <button class="icon-btn" @click="paying = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>

                <p class="pay-total money">{{ money(pos.totals.total) }}</p>

                <label class="field"><span>A nombre de <em>(opcional)</em></span>
                    <input v-model="customer" type="text" maxlength="60" placeholder="Para gritar cuando salga"
                        autocapitalize="words" autocomplete="off">
                </label>

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

        <div v-if="done && pos.lastSale" class="overlay" @click.self="done = false">
            <div class="sheet done-sheet">
                <header class="done-head">
                    <span class="done-mark">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                    <h2 class="done-title">Venta cobrada</h2>
                    <p class="done-total money">{{ money(lastTicket.total) }}</p>
                    <p class="done-when">
                        {{ lastWhen }}
                        <template v-if="lastTicket.unit_name"> · {{ lastTicket.unit_name }}</template>
                    </p>
                </header>

                <!-- Sincronizada o esperando senal: el cajero debe saber si
                     esa venta ya esta en el sistema o vive en la tableta. -->
                <p class="done-sync" :class="pos.lastSale.number ? 'sync-ok' : 'sync-wait'">
                    <span class="sync-dot"></span>
                    <template v-if="pos.lastSale.number">
                        Registrada como <strong>{{ pos.lastSale.number }}</strong>
                    </template>
                    <template v-else>
                        Guardada en el dispositivo · el numero llega al sincronizar
                    </template>
                </p>

                <p v-if="lastTicket.customer_name" class="done-customer">
                    <span>A nombre de</span><strong>{{ lastTicket.customer_name }}</strong>
                </p>

                <!-- Que se cobro -->
                <div class="done-block">
                    <p class="done-block-title">
                        Lo cobrado
                        <span class="done-block-count">{{ lastUnits }} articulo(s)</span>
                    </p>
                    <div v-for="(line, i) in lastTicket.lines" :key="i" class="done-line">
                        <span class="done-line-qty">{{ line.quantity }}×</span>
                        <span class="done-line-name">
                            {{ line.product_name }}
                            <em v-if="line.notes" class="done-line-note">{{ line.notes }}</em>
                        </span>
                        <span class="done-line-unit money">{{ money(line.unit_price_cents) }}</span>
                        <strong class="done-line-total money">{{ money(line.total_cents) }}</strong>
                    </div>
                </div>

                <!-- Como se compone el total -->
                <div class="done-block">
                    <div class="done-row"><span>Subtotal</span><span class="money">{{ money(lastTicket.subtotal) }}</span></div>
                    <div class="done-row">
                        <span>{{ pos.itbisMode === 'added' ? 'ITBIS 18 %' : 'ITBIS incluido' }}</span>
                        <span class="money">{{ money(lastTicket.itbis) }}</span>
                    </div>
                    <div v-if="lastTicket.tip > 0" class="done-row">
                        <span>Propina legal 10 %</span><span class="money">{{ money(lastTicket.tip) }}</span>
                    </div>
                    <div class="done-row done-row-strong">
                        <span>Total</span><strong class="money">{{ money(lastTicket.total) }}</strong>
                    </div>
                </div>

                <!-- Como se pago -->
                <div class="done-block">
                    <p class="done-block-title">Pago</p>
                    <div class="done-row">
                        <span>Metodo</span>
                        <strong class="done-method">{{ metodoLargo[lastTicket.method] ?? lastTicket.method }}</strong>
                    </div>
                    <template v-if="lastTicket.method === 'cash'">
                        <div class="done-row"><span>Recibido</span><span class="money">{{ money(lastTicket.tendered_cents) }}</span></div>
                        <div class="done-row done-row-strong">
                            <span>Vuelto</span>
                            <strong class="money" :class="{ 'done-change': lastTicket.change_cents > 0 }">
                                {{ money(lastTicket.change_cents) }}
                            </strong>
                        </div>
                    </template>
                    <div v-if="lastTicket.cashier" class="done-row"><span>Cobró</span><span>{{ lastTicket.cashier }}</span></div>
                </div>

                <div class="done-actions">
                    <button class="btn-soft" @click="printFrom('comanda')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>
                        Comanda
                    </button>
                    <button class="btn-soft" @click="printFrom('recibo')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 9V3a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v6"/><rect x="6" y="14" width="12" height="8" rx="1"/></svg>
                        Recibo
                    </button>
                </div>

                <button class="btn-primary" @click="done = false">Siguiente venta</button>
            </div>
        </div>

    </div>
</template>

<style scoped>
.sale { flex: 1; display: grid; grid-template-columns: 1fr 350px; min-height: 0; }

/* ---------- Catalogo ---------- */
.catalog { display: flex; flex-direction: column; min-width: 0; min-height: 0; }
.catalog-tools {
    display: flex; align-items: center; gap: 1rem;
    padding: .8rem 1rem;
    background: var(--panel); border-bottom: 1px solid var(--line);
}
.search { position: relative; width: 260px; flex-shrink: 0; }
.search svg {
    position: absolute; left: .8rem; top: 50%; transform: translateY(-50%);
    width: 16px; height: 16px; color: var(--muted); pointer-events: none;
}
.search input { padding: .6rem .9rem .6rem 2.4rem; background: var(--bg); font-size: .9rem; }
.cats { display: flex; gap: .4rem; overflow-x: auto; flex: 1; min-width: 0; scrollbar-width: none; }
.cats::-webkit-scrollbar { display: none; }
.cats button {
    display: inline-flex; align-items: center; gap: .45rem;
    border: 1px solid var(--line-strong); background: var(--panel); color: var(--muted);
    border-radius: 4px; padding: .5rem .85rem; white-space: nowrap;
    font-size: .84rem; font-weight: 600; transition: all .15s; flex-shrink: 0;
}
.cats button:hover { color: var(--text); border-color: var(--muted); }
.cats button.active { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
.cat-count {
    font-size: .74rem; font-weight: 700; padding: .1rem .38rem; border-radius: 4px;
    background: var(--shade); color: inherit; opacity: .75;
}
.cats button.active .cat-count { background: rgba(255, 255, 255, .18); opacity: 1; }

.grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: .85rem; padding: .95rem 1rem 1.2rem; align-content: start; overflow-y: auto;
}
.product {
    display: flex; flex-direction: column;
    border: 1px solid var(--line); background: var(--panel);
    border-radius: 4px; overflow: hidden;
    transition: border-color .15s, transform .1s;
}
.product:hover { border-color: var(--line-strong); }
.product.is-off { opacity: .62; }

.thumb { position: relative; aspect-ratio: 4 / 3; background: var(--panel-2); }
.thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.thumb-fallback {
    display: grid; place-items: center; width: 100%; height: 100%;
    color: rgba(255, 255, 255, .92); font-weight: 800; font-size: 1.6rem; letter-spacing: .04em;
}
.badge {
    position: absolute; top: .45rem; right: .45rem;
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .22rem .5rem; border-radius: 4px;
    background: var(--panel); color: var(--text);
    font-size: .7rem; font-weight: 600; white-space: nowrap;
}
.badge-dot { width: 6px; height: 6px; border-radius: 4px; }
.badge-on .badge-dot { background: var(--ok); }
.badge-off .badge-dot { background: var(--bad); }

.product-body { display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; padding: .65rem .7rem .5rem; }
.product-name {
    font-size: .88rem; font-weight: 500; line-height: 1.25; min-width: 0;
    display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
}
.product-price { font-size: .9rem; white-space: nowrap; }

.product-btn {
    display: flex; align-items: center; justify-content: center; gap: .4rem;
    margin: 0 .7rem .7rem; padding: .6rem; border-radius: 4px;
    background: var(--accent); color: var(--on-accent);
    font-size: .84rem; font-weight: 600; transition: filter .15s, transform .1s;
}
.product-btn svg { width: 15px; height: 15px; }
.product-btn:not(:disabled):hover { filter: brightness(1.12); }
.product-btn:not(:disabled):active { transform: scale(.97); }
.product-btn-more {
    background: var(--panel); color: var(--text); border: 1px solid var(--line-strong);
}
.product-btn-off { background: var(--panel-2); color: var(--muted); cursor: not-allowed; }
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
    display: flex; align-items: flex-start; gap: .65rem;
    padding: .7rem 0; border-bottom: 1px solid var(--line);
}
.line-thumb {
    width: 48px; height: 48px; flex-shrink: 0; border-radius: 4px; overflow: hidden;
    background: var(--panel-2);
}
.line-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.line-thumb .thumb-fallback { font-size: .82rem; }
.line-info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .12rem; }
.line-name { font-size: .86rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.line-unit { font-size: .72rem; color: var(--muted); }
.line-total { font-size: .88rem; font-weight: 600; }

/* La nota se ve siempre, puesta o no: si hubiera que descubrirla con una
   pulsación larga, nadie la usaría en una barra llena. */
.line-note {
    display: flex; align-items: center; gap: .28rem; margin: .1rem 0;
    padding: 0; background: none; border: 0; cursor: pointer;
    font-size: .72rem; color: var(--muted); text-align: left;
}
.line-note svg { width: .78rem; height: .78rem; flex: none; }
.line-note span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.line-note.set { color: var(--warn); font-weight: 600; }

.note-hint { margin: 0 0 .6rem; font-size: .82rem; color: var(--muted); }
.line-side { display: flex; flex-direction: column; align-items: flex-end; gap: .35rem; }
.stepper {
    display: flex; align-items: center; gap: .1rem;
    background: var(--panel-2); border: 1px solid var(--line); border-radius: 4px;
}
.step {
    width: 28px; height: 28px; display: grid; place-items: center;
    font-size: 1.05rem; color: var(--muted); border-radius: 4px; transition: all .12s;
}
.step:hover { color: var(--bad); background: var(--shade); }
.step-add:hover { color: var(--ok); }
.qty { min-width: 1.4rem; text-align: center; font-weight: 700; font-size: .86rem; }
.line-drop {
    display: grid; place-items: center; width: 26px; height: 26px;
    border-radius: 4px; color: var(--muted); transition: all .12s;
}
.line-drop svg { width: 15px; height: 15px; }
.line-drop:hover { color: var(--bad); background: var(--shade); }

.ticket-foot { padding: .8rem 1rem 1rem; border-top: 1px solid var(--line); display: flex; flex-direction: column; gap: .7rem; }

.tip-row {
    display: flex; align-items: center; justify-content: space-between;
    font-size: .86rem; color: var(--text); cursor: pointer;
}
.switch { position: relative; width: 42px; height: 24px; display: inline-block; }
.switch input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.switch .knob {
    position: absolute; inset: 0; border-radius: 4px; background: var(--panel-2);
    border: 1px solid var(--line-strong); transition: background .15s, border-color .15s;
    pointer-events: none;
}
.switch .knob::after {
    content: ''; position: absolute; top: 2px; left: 2px; width: 18px; height: 18px;
    border-radius: 4px; background: var(--muted); transition: transform .15s, background .15s;
}
.switch.on .knob { background: var(--accent); border-color: var(--accent); }
.switch.on .knob::after { transform: translateX(18px); background: var(--on-accent); }

.totals { display: flex; flex-direction: column; gap: .3rem; }
.trow { display: flex; justify-content: space-between; font-size: .82rem; color: var(--muted); }
.trow-total { margin-top: .2rem; padding-top: .55rem; border-top: 1px dashed var(--line-strong); color: var(--text); align-items: baseline; }
.trow-total strong { font-size: 1.45rem; letter-spacing: -.01em; }

/* ---------- Cobro ---------- */
.pay-total { font-size: 2.1rem; font-weight: 800; letter-spacing: -.02em; margin-bottom: 1rem; }
.segmented {
    display: grid; grid-template-columns: repeat(3, 1fr); gap: .3rem;
    background: var(--panel-2); border: 1px solid var(--line); border-radius: 4px;
    padding: .3rem; margin-bottom: 1rem;
}
.segmented button {
    padding: .6rem .4rem; border-radius: 4px; font-size: .86rem; font-weight: 600;
    color: var(--muted); transition: all .15s;
}
.segmented button.active { background: var(--accent); color: var(--on-accent); }
.pay-input { font-size: 1.25rem; font-weight: 700; }
.pay-chips { margin: -.3rem 0 .9rem; }
.change { color: var(--ok); font-size: 1.05rem; font-weight: 700; margin-bottom: .9rem; }
.short { color: var(--bad); font-size: 1rem; font-weight: 600; margin-bottom: .9rem; }

/* ---------- Venta cobrada ---------- */
.done-sheet { width: min(420px, 100%); }
.done-head { text-align: center; margin-bottom: .9rem; }
.done-mark {
    display: grid; place-items: center; width: 50px; height: 50px; margin: 0 auto .7rem;
    border-radius: 4px; background: var(--ok); color: #fff;
}
.done-mark svg { width: 26px; height: 26px; }
.done-title { font-size: 1rem; margin-bottom: .15rem; }
.done-total { font-size: 2.1rem; font-weight: 800; letter-spacing: -.02em; }
.done-when { font-size: .76rem; color: var(--muted); margin-top: .15rem; }

.done-sync {
    display: flex; align-items: center; justify-content: center; gap: .45rem;
    font-size: .78rem; padding: .5rem; border-radius: 4px; margin-bottom: .8rem;
}
.done-sync strong { font-weight: 700; }
.sync-dot { width: 7px; height: 7px; border-radius: 4px; flex-shrink: 0; }
.sync-ok { background: var(--shade); color: var(--ok); }
.sync-ok .sync-dot { background: var(--ok); }
.sync-wait { background: var(--shade); color: var(--warn); }
.sync-wait .sync-dot { background: var(--warn); }

.done-customer {
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    border: 1px solid var(--line-strong); border-radius: 4px;
    padding: .55rem .7rem; margin-bottom: .8rem; font-size: .86rem; color: var(--muted);
}
.done-customer strong { color: var(--text); font-size: .95rem; }

.done-block {
    border-top: 1px solid var(--line); padding: .7rem 0;
    display: flex; flex-direction: column; gap: .3rem;
}
.done-block-title {
    display: flex; align-items: baseline; justify-content: space-between; gap: 1rem;
    font-size: .72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    color: var(--muted); margin-bottom: .25rem;
}
.done-block-count { font-weight: 500; letter-spacing: 0; text-transform: none; }

.done-line { display: flex; align-items: baseline; gap: .5rem; font-size: .84rem; }
.done-line-qty { min-width: 1.7rem; font-weight: 700; color: var(--text); }
.done-line-name { flex: 1; min-width: 0; color: var(--text); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.done-line-note { display: block; font-size: .74rem; font-style: normal; color: var(--warn); }
.done-line-unit { color: var(--muted); font-size: .76rem; white-space: nowrap; }
.done-line-total { min-width: 4.6rem; text-align: right; white-space: nowrap; }

.done-row { display: flex; justify-content: space-between; gap: 1rem; font-size: .84rem; color: var(--muted); }
.done-row strong, .done-row span:last-child { color: var(--text); }
.done-row-strong { font-size: .95rem; padding-top: .2rem; }
.done-method { font-weight: 700; }
.done-change { color: var(--ok); }
.done-actions { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; margin: .9rem 0 .8rem; }
.done-actions .btn-soft {
    display: flex; align-items: center; justify-content: center; gap: .4rem; padding: .7rem;
}
.done-actions svg { width: 16px; height: 16px; }

/* ---------- Movil ---------- */
@media (max-width: 900px) {
    .catalog-tools { flex-direction: column; align-items: stretch; gap: .55rem; }
    .search { width: 100%; }
}
@media (max-width: 760px) {
    .sale { grid-template-columns: 1fr; grid-template-rows: 1fr auto; }
    .ticket { border-left: 0; border-top: 1px solid var(--line-strong); max-height: 46vh; max-height: 46dvh; overflow-y: auto; }
    .grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: .6rem; padding: .7rem; }
}
</style>
