<script setup>
import { ref } from 'vue';
import { usePantalla } from '../store';

const pantalla = usePantalla();
const emit = defineEmits(['cerrar']);

const q = ref('');

const AREAS = { bar: 'Barra', kitchen: 'Cocina' };

// 24 horas: en una cocina, «21:40» se lee de un vistazo y «09:40 p. m.» no.
const hora = (marca) => marca
    ? new Date(marca).toLocaleTimeString('es-DO', { hour: '2-digit', minute: '2-digit', hour12: false })
    : null;

// Lo que hay que poder contestarle a alguien que está preguntando por lo
// suyo. Con la hora cuando la hay: «lista» a secas no calma a nadie.
function comoVa(a) {
    if (a.ready_at) return `lista ${hora(a.ready_at)}`;
    if (a.started_at) return `empezó ${hora(a.started_at)}`;

    return 'todavía sin empezar';
}
</script>

<template>
    <div class="overlay" @click.self="emit('cerrar')">
        <div class="sheet buscar">
            <div class="sheet-head">
                <h2>¿Y lo mío?</h2>
                <button class="icon-btn" @click="emit('cerrar')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>

            <label class="field"><span>Número o nombre</span>
                <input v-model="q" type="text" placeholder="0041 o Juan" autocomplete="off"
                    autofocus @keyup.enter="pantalla.buscar(q)">
            </label>

            <button class="btn-primary" :disabled="pantalla.buscando" @click="pantalla.buscar(q)">
                {{ pantalla.buscando ? 'Buscando...' : 'Buscar' }}
            </button>

            <div v-if="pantalla.resultados" class="resultados">
                <p v-if="pantalla.resultados.length === 0" class="vacio">
                    Nada con eso hoy en este puesto.
                </p>

                <!-- Se responde con HORAS, no con estados a secas: al cliente
                     que reclama no le sirve «lista», le sirve «te la
                     entregamos a las 21:42». -->
                <div v-for="(r, i) in pantalla.resultados" :key="i" class="resultado">
                    <strong class="r-num">{{ r.number }}</strong>
                    <div class="r-cuerpo">
                        <p v-if="r.customer_name" class="r-nombre">{{ r.customer_name }}</p>
                        <p class="r-linea">Cobrada {{ hora(r.device_sold_at ?? r.paid_at) }}</p>
                        <p v-for="(a, j) in r.areas ?? []" :key="j" class="r-linea">
                            {{ AREAS[a.area] ?? a.area }}: {{ comoVa(a) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.buscar { width: min(520px, 100%); }
.resultados { margin-top: 1rem; display: flex; flex-direction: column; gap: .6rem; }
.vacio { color: var(--muted); font-size: .86rem; text-align: center; padding: .8rem 0; }
.resultado { display: flex; gap: .8rem; padding: .7rem 0; border-top: 1px solid var(--line); }
.r-num { font-size: 1.5rem; font-variant-numeric: tabular-nums; }
.r-cuerpo { flex: 1; min-width: 0; }
.r-nombre { font-weight: 600; }
.r-linea { font-size: .82rem; color: var(--muted); }
</style>
