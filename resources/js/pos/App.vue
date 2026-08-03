<script setup>
import { computed, ref, watchEffect } from 'vue';
import { usePos } from './store';
import { money, toCents } from './money';
import LoginScreen from './components/LoginScreen.vue';
import TillScreen from './components/TillScreen.vue';
import SaleScreen from './components/SaleScreen.vue';
import SalesScreen from './components/SalesScreen.vue';
import TicketPrint from './components/TicketPrint.vue';

const pos = usePos();

// El cierre de caja es administracion, no venta: vive en la barra, lejos
// del boton de cobrar.
const counting = ref(false);
const counted = ref('');

function closeTill() {
    pos.closeTill(toCents(counted.value));
    counting.value = false;
    counted.value = '';
}

const unitName = computed(() =>
    pos.units.find((unit) => unit.id === pos.session?.operating_unit_id)?.name ?? null);

// El tema es del DISPOSITIVO, no de la cuenta: la tableta de la barra puede
// estar en oscuro y la de la caja de la entrada en claro. Va en localStorage
// y no en el outbox de Dexie porque debe leerse antes del primer pintado.
const theme = ref(localStorage.getItem('pos:theme') ?? 'light');

watchEffect(() => {
    document.documentElement.dataset.theme = theme.value;
    localStorage.setItem('pos:theme', theme.value);
});

function toggleTheme() {
    theme.value = theme.value === 'dark' ? 'light' : 'dark';
}

// La hora en pantalla, como en el mostrador de un restaurante. Se refresca
// cada minuto: al segundo no le sirve a nadie y repinta sesenta veces más.
const now = ref(new Date());
setInterval(() => { now.value = new Date(); }, 60_000);

const clock = computed(() => new Intl.DateTimeFormat('es-DO', {
    weekday: 'long', day: 'numeric', month: 'long', hour: 'numeric', minute: '2-digit',
}).format(now.value));

const statusLabel = {
    pendiente: 'Por sincronizar',
    sin_caja: 'Sin caja',
    error: 'En revision',
    sincronizada: 'Sincronizada',
    descartada: 'Descartada',
};
</script>

<template>
    <div class="pos-app">
        <header v-if="pos.screen !== 'login'" class="topbar">
            <div class="brand">
                <span class="brand-mark">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/></svg>
                </span>
                <div class="brand-text">
                    <strong>POS</strong>
                    <span v-if="unitName">{{ unitName }}</span>
                </div>
            </div>

            <div class="status">
                <span class="pill" :class="pos.online ? 'pill-ok' : 'pill-off'">
                    <span class="dot" :class="pos.online ? 'dot-ok' : 'dot-off'"></span>
                    {{ pos.online ? 'En linea' : 'Sin senal' }}
                </span>
                <button v-if="pos.pending > 0" class="pill pill-warn" @click="pos.openReview()">
                    {{ pos.pending }} por sincronizar
                </button>
                <button v-if="pos.errored > 0" class="pill pill-bad" @click="pos.openReview()">
                    {{ pos.errored }} en revision
                </button>
            </div>

            <div class="session">
                <span class="clock">{{ clock }}</span>
                <button class="icon-btn" :title="theme === 'dark' ? 'Cambiar a claro' : 'Cambiar a oscuro'" @click="toggleTheme()">
                    <svg v-if="theme === 'dark'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/></svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg>
                </button>
                <span v-if="pos.user" class="user-chip">{{ pos.user.name }}</span>
                <button v-if="pos.screen === 'sale'" class="btn-topbar" @click="pos.screen = 'sales'">Ventas</button>
                <button v-if="pos.screen === 'sales'" class="btn-topbar" @click="pos.screen = 'sale'">Vender</button>
                <button v-if="pos.screen === 'sale'" class="btn-topbar" @click="counting = true">Cerrar caja</button>
                <button class="icon-btn" title="Salir" @click="pos.logout()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/></svg>
                </button>
            </div>
        </header>

        <div v-if="counting" class="overlay" @click.self="counting = false">
            <div class="sheet">
                <div class="sheet-head">
                    <h2>Cerrar caja</h2>
                    <button class="icon-btn" @click="counting = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>
                <label class="field"><span>Efectivo contado (RD$)</span>
                    <input v-model="counted" type="text" inputmode="decimal" placeholder="0.00" class="money">
                </label>
                <p class="close-note">Se cierra contra <strong class="money">{{ money(toCents(counted)) }}</strong>. Irreversible desde el POS.</p>
                <button class="btn-primary" :disabled="pos.busy || counted === ''" @click="closeTill()">
                    Cerrar contra lo contado
                </button>
            </div>
        </div>

        <transition name="toast">
            <button v-if="pos.error" class="error-toast" @click="pos.error = null">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
                <span>{{ pos.error }}</span>
            </button>
        </transition>

        <div v-if="pos.reviewing" class="overlay" @click.self="pos.reviewing = false">
            <div class="sheet review-sheet">
                <div class="sheet-head">
                    <h2>Bandeja del dispositivo</h2>
                    <button class="icon-btn" @click="pos.reviewing = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>
                <div v-for="row in pos.reviewRows" :key="row.id" class="review-row">
                    <div class="review-info">
                        <div class="review-title">
                            <strong>{{ row.server?.number ?? row.client_ref.slice(0, 8) }}</strong>
                            <span class="review-chip" :class="'chip-' + row.status">{{ statusLabel[row.status] ?? row.status }}</span>
                        </div>
                        <span class="review-sub">{{ row.lines.length }} linea(s) · {{ money(row.display?.total ?? 0) }}</span>
                        <p v-if="row.error_message" class="review-msg">{{ row.error_message }}</p>
                    </div>
                    <div class="review-actions">
                        <button class="btn-soft" @click="pos.retryRow(row.id)">Reintentar</button>
                        <button v-if="row.status === 'error'" class="btn-soft btn-danger" @click="pos.discardRow(row.id)">Descartar</button>
                    </div>
                </div>
                <p v-if="pos.reviewRows.length === 0" class="review-empty">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                    Nada pendiente: todo sincronizado.
                </p>
            </div>
        </div>

        <!-- El ticket cuelga de <body> para que la hoja de impresion pueda
             esconder todo lo demas con una sola regla. -->
        <Teleport to="body">
            <div v-if="pos.printJob" class="print-root">
                <TicketPrint :ticket="pos.printJob.ticket" :kind="pos.printJob.kind" :number="pos.printJob.number" />
            </div>
        </Teleport>

        <LoginScreen v-if="pos.screen === 'login'" />
        <TillScreen v-else-if="pos.screen === 'till'" />
        <SalesScreen v-else-if="pos.screen === 'sales'" />
        <SaleScreen v-else />
    </div>
</template>

<style>
/*
 * Lo propio del POS. Las variables del tema, los overlays, los botones y los
 * campos viven en resources/css/device-theme.css, que comparte con la
 * pantalla de cocina — importado desde main.js.
 */
.pos-app { min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; }

/* ---------- Barra superior ---------- */
.topbar {
    display: flex; align-items: center; gap: .75rem;
    padding: .55rem .9rem;
    background: var(--panel);
    border-bottom: 1px solid var(--line);
    position: sticky; top: 0; z-index: 10;
}
.brand { display: flex; align-items: center; gap: .6rem; min-width: 0; }
.brand-mark {
    display: grid; place-items: center; width: 34px; height: 34px; border-radius: 4px;
    background: var(--accent); color: var(--on-accent); flex-shrink: 0;
}
.brand-mark svg { width: 18px; height: 18px; }
.brand-text { display: flex; flex-direction: column; line-height: 1.15; min-width: 0; }
.brand-text strong { font-size: .9rem; letter-spacing: .02em; }
.brand-text span { font-size: .72rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.status { display: flex; align-items: center; gap: .45rem; flex: 1; justify-content: center; flex-wrap: wrap; }

.session { display: flex; align-items: center; gap: .5rem; }
.user-chip { font-size: .78rem; color: var(--muted); white-space: nowrap; }
.clock { font-size: .78rem; color: var(--muted); white-space: nowrap; text-transform: capitalize; }
@media (max-width: 900px) { .clock { display: none; } }
/* ---------- Bandeja ---------- */
.review-sheet { width: min(560px, 100%); }
.review-row {
    display: flex; justify-content: space-between; gap: .8rem; align-items: center;
    padding: .7rem .2rem; border-bottom: 1px solid var(--line);
}
.review-row:last-of-type { border-bottom: 0; }
.review-title { display: flex; align-items: center; gap: .5rem; }
.review-chip { font-size: .68rem; font-weight: 600; padding: .14rem .5rem; border-radius: 4px; }
.chip-pendiente, .chip-sin_caja { background: color-mix(in srgb, var(--warn) 14%, transparent); color: var(--warn); }
.chip-error { background: color-mix(in srgb, var(--bad) 14%, transparent); color: var(--bad); }
.chip-sincronizada { background: color-mix(in srgb, var(--ok) 12%, transparent); color: var(--ok); }
.chip-descartada { background: var(--shade); color: var(--muted); }
.review-sub { font-size: .78rem; color: var(--muted); }
.review-msg { color: var(--bad); font-size: .78rem; margin-top: .2rem; }
.review-empty {
    display: flex; align-items: center; gap: .5rem; justify-content: center;
    color: var(--muted); padding: 1.2rem 0; font-size: .9rem;
}
.review-empty svg { width: 18px; height: 18px; color: var(--ok); }
.review-actions { display: flex; gap: .4rem; flex-shrink: 0; }

.btn-topbar {
    border: 1px solid var(--line-strong); border-radius: 4px;
    padding: .45rem .75rem; font-size: .78rem; font-weight: 600;
    color: var(--muted); white-space: nowrap; transition: all .15s;
}
.btn-topbar:hover { color: var(--text); background: var(--panel-2); }

.close-note { color: var(--muted); font-size: .84rem; margin-bottom: 1rem; }
.close-note strong { color: var(--text); }
</style>
