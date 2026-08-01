<script setup>
import { ref } from 'vue';
import { usePos } from '../store';
import { money, toCents } from '../money';

const pos = usePos();
const unitId = ref(null);
const opening = ref('');
</script>

<template>
    <div class="screen">
        <template v-if="pos.closing">
            <h2>Caja cerrada</h2>
            <p>Esperado: <strong>{{ money(pos.closing.expected_cents) }}</strong></p>
            <p>Contado: <strong>{{ money(pos.closing.closing_cents) }}</strong></p>
            <p>Diferencia: <strong :class="pos.closing.difference_cents < 0 ? 'faltante' : ''">
                {{ money(pos.closing.difference_cents) }}</strong></p>
            <br>
            <button class="primary" @click="pos.closing = null">Abrir una caja nueva</button>
        </template>
        <template v-else>
            <h2>Abrir caja</h2>
            <label class="field"><span>Unidad</span>
                <select v-model="unitId">
                    <option v-for="unit in pos.units" :key="unit.id" :value="unit.id">{{ unit.name }}</option>
                </select>
            </label>
            <label class="field"><span>Fondo inicial (RD$)</span>
                <input v-model="opening" type="text" inputmode="decimal">
            </label>
            <button class="primary" :disabled="pos.busy || !unitId"
                @click="pos.openTill(unitId, toCents(opening))">
                Abrir caja
            </button>
        </template>
    </div>
</template>

<style scoped>
.faltante { color: #f87171; }
p { margin-bottom: .5rem; }
</style>
