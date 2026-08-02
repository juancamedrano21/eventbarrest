<script setup>
import { computed, ref } from 'vue';
import { usePos } from './store';
import { money, toCents } from './money';
import LoginScreen from './components/LoginScreen.vue';
import TillScreen from './components/TillScreen.vue';
import SaleScreen from './components/SaleScreen.vue';
import SalesScreen from './components/SalesScreen.vue';

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

        <LoginScreen v-if="pos.screen === 'login'" />
        <TillScreen v-else-if="pos.screen === 'till'" />
        <SalesScreen v-else-if="pos.screen === 'sales'" />
        <SaleScreen v-else />
    </div>
</template>

<style>
:root {
    --bg: #090e1a;
    --panel: #101828;
    --panel-2: #17223a;
    --line: rgba(148, 163, 184, .14);
    --line-strong: rgba(148, 163, 184, .28);
    --text: #e8edf6;
    --muted: #8b99b3;
    --accent: #0ea5e9;
    --accent-2: #38bdf8;
    --accent-strong: #0284c7;
    --ok: #34d399;
    --warn: #fbbf24;
    --bad: #f87171;
}

* { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, system-ui, sans-serif;
    -webkit-font-smoothing: antialiased;
}
button { font: inherit; color: inherit; cursor: pointer; background: none; border: 0; -webkit-tap-highlight-color: transparent; }
input, select { font: inherit; }

.pos-app { min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; }

/* ---------- Barra superior ---------- */
.topbar {
    display: flex; align-items: center; gap: .75rem;
    padding: .55rem .9rem;
    background: rgba(16, 24, 40, .92);
    border-bottom: 1px solid var(--line);
    backdrop-filter: blur(8px);
    position: sticky; top: 0; z-index: 10;
}
.brand { display: flex; align-items: center; gap: .6rem; min-width: 0; }
.brand-mark {
    display: grid; place-items: center; width: 34px; height: 34px; border-radius: 4px;
    background: linear-gradient(135deg, #0ea5e9, #0369a1);
   
    color: white; flex-shrink: 0;
}
.brand-mark svg { width: 18px; height: 18px; }
.brand-text { display: flex; flex-direction: column; line-height: 1.15; min-width: 0; }
.brand-text strong { font-size: .9rem; letter-spacing: .02em; }
.brand-text span { font-size: .72rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.status { display: flex; align-items: center; gap: .45rem; flex: 1; justify-content: center; flex-wrap: wrap; }
.pill {
    display: inline-flex; align-items: center; gap: .4rem;
    font-size: .74rem; font-weight: 600; letter-spacing: .01em;
    padding: .32rem .7rem; border-radius: 4px; border: 1px solid transparent;
}
.pill-ok { background: rgba(52, 211, 153, .1); color: #6ee7b7; border-color: rgba(52, 211, 153, .25); }
.pill-off { background: rgba(248, 113, 113, .12); color: #fca5a5; border-color: rgba(248, 113, 113, .3); }
.pill-warn { background: rgba(251, 191, 36, .12); color: #fcd34d; border-color: rgba(251, 191, 36, .3); }
.pill-bad { background: rgba(248, 113, 113, .12); color: #fca5a5; border-color: rgba(248, 113, 113, .3); }
.dot { width: 7px; height: 7px; border-radius: 4px; position: relative; }
.dot-ok { background: var(--ok); }
.dot-ok::after {
    content: ''; position: absolute; inset: -3px; border-radius: 4px;
    background: rgba(52, 211, 153, .4); animation: pulse 2s ease-out infinite;
}
.dot-off { background: var(--bad); }
@keyframes pulse { 0% { transform: scale(.6); opacity: 1; } 100% { transform: scale(1.8); opacity: 0; } }

.session { display: flex; align-items: center; gap: .5rem; }
.user-chip { font-size: .78rem; color: var(--muted); white-space: nowrap; }
.icon-btn {
    display: grid; place-items: center; width: 36px; height: 36px; border-radius: 4px;
    border: 1px solid var(--line); color: var(--muted); transition: all .15s;
}
.icon-btn:hover { color: var(--text); border-color: var(--line-strong); background: var(--panel-2); }
.icon-btn svg { width: 17px; height: 17px; }

/* ---------- Toast de error ---------- */
.error-toast {
    position: fixed; top: 4.2rem; left: 50%; transform: translateX(-50%);
    display: flex; align-items: center; gap: .6rem; text-align: left;
    max-width: min(560px, calc(100vw - 2rem));
    background: #2c1214; border: 1px solid rgba(248, 113, 113, .4); color: #fecaca;
    padding: .7rem 1rem; border-radius: 4px; z-index: 40;
   
    font-size: .875rem;
}
.error-toast svg { width: 18px; height: 18px; flex-shrink: 0; color: var(--bad); }
.toast-enter-active, .toast-leave-active { transition: all .2s ease; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(-50%) translateY(-8px); }

/* ---------- Overlays y hojas ---------- */
.overlay {
    position: fixed; inset: 0; background: rgba(3, 7, 18, .72);
    display: grid; place-items: center; z-index: 30; padding: 1rem;
    backdrop-filter: blur(3px);
}
.sheet {
    background: var(--panel); border: 1px solid var(--line-strong);
    border-radius: 4px; padding: 1.25rem;
    width: min(440px, 100%); max-height: 86vh; overflow-y: auto;
   
}
.sheet-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .9rem; }
.sheet-head h2 { font-size: 1.05rem; }

/* ---------- Bandeja ---------- */
.review-sheet { width: min(560px, 100%); }
.review-row {
    display: flex; justify-content: space-between; gap: .8rem; align-items: center;
    padding: .7rem .2rem; border-bottom: 1px solid var(--line);
}
.review-row:last-of-type { border-bottom: 0; }
.review-title { display: flex; align-items: center; gap: .5rem; }
.review-chip { font-size: .68rem; font-weight: 600; padding: .14rem .5rem; border-radius: 4px; }
.chip-pendiente, .chip-sin_caja { background: rgba(251, 191, 36, .14); color: #fcd34d; }
.chip-error { background: rgba(248, 113, 113, .14); color: #fca5a5; }
.chip-sincronizada { background: rgba(52, 211, 153, .12); color: #6ee7b7; }
.chip-descartada { background: rgba(148, 163, 184, .14); color: #b6c2d6; }
.review-sub { font-size: .78rem; color: var(--muted); }
.review-msg { color: #fca5a5; font-size: .78rem; margin-top: .2rem; }
.review-empty {
    display: flex; align-items: center; gap: .5rem; justify-content: center;
    color: var(--muted); padding: 1.2rem 0; font-size: .9rem;
}
.review-empty svg { width: 18px; height: 18px; color: var(--ok); }
.review-actions { display: flex; gap: .4rem; flex-shrink: 0; }

/* ---------- Piezas compartidas ---------- */
.btn-soft {
    border: 1px solid var(--line-strong); border-radius: 4px;
    padding: .45rem .8rem; font-size: .8rem; color: var(--text);
    transition: background .15s;
}
.btn-soft:hover { background: var(--panel-2); }
.btn-danger { color: #fca5a5; border-color: rgba(248, 113, 113, .35); }

.btn-topbar {
    border: 1px solid var(--line-strong); border-radius: 4px;
    padding: .45rem .75rem; font-size: .78rem; font-weight: 600;
    color: var(--muted); white-space: nowrap; transition: all .15s;
}
.btn-topbar:hover { color: var(--text); background: var(--panel-2); }

.close-note { color: var(--muted); font-size: .84rem; margin-bottom: 1rem; }
.close-note strong { color: var(--text); }

.btn-primary {
    width: 100%; display: flex; align-items: center; justify-content: center; gap: .5rem;
    padding: .95rem 1rem; border-radius: 4px;
    background: linear-gradient(135deg, #0ea5e9, #0369a1);
    color: white; font-size: 1.02rem; font-weight: 700; letter-spacing: .01em;
   
    transition: transform .1s, filter .15s;
}
.btn-primary:not(:disabled):active { transform: scale(.985); }
.btn-primary:not(:disabled):hover { filter: brightness(1.08); }
.btn-primary:disabled { opacity: .45; cursor: not-allowed; }

.field { display: block; margin-bottom: 1rem; }
.field > span {
    display: block; font-size: .74rem; font-weight: 600; letter-spacing: .04em;
    text-transform: uppercase; color: var(--muted); margin-bottom: .4rem;
}
input, select {
    width: 100%; padding: .8rem .9rem; border-radius: 4px;
    border: 1px solid var(--line-strong); background: var(--panel-2);
    color: var(--text); font-size: 1rem; outline: none;
    transition: border-color .15s;
}
input:focus, select:focus { border-color: var(--accent); }
select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%238b99b3' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .8rem center; padding-right: 2.4rem; }

.money { font-variant-numeric: tabular-nums; }

.chips { display: flex; flex-wrap: wrap; gap: .45rem; }
.chip-btn {
    border: 1px solid var(--line-strong); border-radius: 4px;
    padding: .42rem .85rem; font-size: .82rem; font-weight: 600; color: var(--text);
    transition: all .15s;
}
.chip-btn:hover { border-color: var(--accent); color: var(--accent-2); }
</style>
