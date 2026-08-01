<script setup>
import { ref } from 'vue';
import { usePos } from '../store';
import { money, toCents } from '../money';

const pos = usePos();
const unitId = ref(null);
const opening = ref('');

const fondos = [0, 500, 1000, 2000, 5000];
</script>

<template>
    <div class="till">
        <template v-if="pos.closing">
            <div class="till-card">
                <div class="till-icon till-icon-ok">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                </div>
                <h1>Caja cerrada</h1>
                <p class="till-sub">El arqueo quedó registrado; nada se puede retocar desde el POS.</p>

                <div class="stats">
                    <div class="stat">
                        <span>Esperado</span>
                        <strong class="money">{{ money(pos.closing.expected_cents) }}</strong>
                    </div>
                    <div class="stat">
                        <span>Contado</span>
                        <strong class="money">{{ money(pos.closing.closing_cents) }}</strong>
                    </div>
                    <div class="stat">
                        <span>Diferencia</span>
                        <strong class="money" :class="pos.closing.difference_cents < 0 ? 'neg' : 'pos'">
                            {{ money(pos.closing.difference_cents) }}
                        </strong>
                    </div>
                </div>

                <button class="btn-primary" @click="pos.closing = null">Abrir una caja nueva</button>
            </div>
        </template>

        <template v-else>
            <form class="till-card" @submit.prevent="pos.openTill(unitId, toCents(opening))">
                <div class="till-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="12" x="2" y="6" rx="2"/><circle cx="12" cy="12" r="2"/><path d="M6 12h.01M18 12h.01"/></svg>
                </div>
                <h1>Abrir caja</h1>
                <p class="till-sub">Elige tu unidad y declara el fondo con el que arrancas.</p>

                <label class="field"><span>Unidad</span>
                    <select v-model="unitId" required>
                        <option v-for="unit in pos.units" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                    </select>
                </label>

                <label class="field"><span>Fondo inicial (RD$)</span>
                    <input v-model="opening" type="text" inputmode="decimal" placeholder="0.00">
                </label>

                <div class="chips fondo-chips">
                    <button v-for="fondo in fondos" :key="fondo" type="button" class="chip-btn"
                        @click="opening = String(fondo)">
                        {{ fondo === 0 ? 'Sin fondo' : 'RD$ ' + fondo.toLocaleString('es-DO') }}
                    </button>
                </div>

                <button type="submit" class="btn-primary" :disabled="pos.busy || !unitId">
                    {{ pos.busy ? 'Abriendo...' : 'Abrir caja' }}
                </button>
            </form>
        </template>
    </div>
</template>

<style scoped>
.till { flex: 1; display: grid; place-items: center; padding: 1.2rem; }
.till-card {
    width: min(420px, 100%);
    background: var(--panel); border: 1px solid var(--line-strong);
    border-radius: 4px; padding: 1.8rem 1.6rem;
   
}
.till-icon {
    display: grid; place-items: center; width: 50px; height: 50px; border-radius: 4px;
    background: linear-gradient(135deg, #0ea5e9, #0369a1);
   
    color: white; margin-bottom: 1rem;
}
.till-icon svg { width: 24px; height: 24px; }
.till-icon-ok { background: linear-gradient(135deg, #10b981, #047857); }
h1 { font-size: 1.3rem; margin-bottom: .25rem; }
.till-sub { color: var(--muted); font-size: .87rem; margin-bottom: 1.4rem; }

.stats { display: flex; flex-direction: column; gap: .1rem; margin-bottom: 1.4rem; }
.stat {
    display: flex; justify-content: space-between; align-items: center;
    padding: .75rem .2rem; border-bottom: 1px solid var(--line);
}
.stat:last-child { border-bottom: 0; }
.stat span { color: var(--muted); font-size: .86rem; }
.stat strong { font-size: 1.05rem; }
.stat .neg { color: var(--bad); }
.stat .pos { color: var(--ok); }

.fondo-chips { margin: -.3rem 0 1.2rem; }
</style>
