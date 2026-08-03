<script setup>
import { computed, ref } from 'vue';
import { usePantalla } from '../store';
import ComandaCard from './ComandaCard.vue';

const pantalla = usePantalla();

const COLUMNAS = [
    { clave: 'pending', titulo: 'Pendiente' },
    { clave: 'in_progress', titulo: 'En proceso' },
    { clave: 'ready', titulo: 'Lista' },
];

// Por debajo de 900 px las tres columnas no caben y se vuelven una lista con
// filtro. Nunca scroll horizontal: buscar una comanda deslizando de lado, con
// las manos ocupadas, no lo hace nadie.
const estrecha = ref(window.innerWidth < 900);
window.addEventListener('resize', () => { estrecha.value = window.innerWidth < 900; });

const filtro = ref('pending');

const visibles = computed(() => estrecha.value
    ? { [filtro.value]: pantalla.columnas[filtro.value] }
    : pantalla.columnas);
</script>

<template>
    <div class="tablero">
        <div v-if="estrecha" class="filtros">
            <button v-for="col in COLUMNAS" :key="col.clave"
                :class="{ activo: filtro === col.clave }" @click="filtro = col.clave">
                {{ col.titulo }}
                <span class="cuenta">{{ pantalla.columnas[col.clave].length }}</span>
            </button>
        </div>

        <div class="columnas" :class="{ una: estrecha }">
            <section v-for="col in COLUMNAS.filter((c) => visibles[c.clave])" :key="col.clave" class="columna">
                <header v-if="!estrecha" class="col-head">
                    <h2>{{ col.titulo }}</h2>
                    <span class="cuenta">{{ pantalla.columnas[col.clave].length }}</span>
                </header>

                <div class="col-cuerpo">
                    <ComandaCard v-for="fila in pantalla.columnas[col.clave]"
                        :key="fila.order_id + ':' + fila.area" :fila="fila" />

                    <p v-if="pantalla.columnas[col.clave].length === 0" class="col-vacia">
                        <!-- «No pude preguntar» no es «no hay nada»: si la
                             pantalla está a ciegas, la franja de arriba manda. -->
                        {{ pantalla.aCiegas ? 'Sin datos frescos' : 'Nada por aquí' }}
                    </p>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
.tablero { flex: 1; display: flex; flex-direction: column; min-height: 0; }

.filtros { display: flex; gap: .4rem; padding: .6rem .8rem 0; }
.filtros button {
    flex: 1; padding: .7rem .4rem; border-radius: 4px; font-weight: 700; font-size: .84rem;
    border: 1px solid var(--line-strong); color: var(--muted);
}
.filtros button.activo { background: var(--panel); color: var(--text); border-color: var(--accent-2); }

.columnas { flex: 1; display: grid; grid-template-columns: repeat(3, 1fr); gap: .8rem; padding: .8rem; min-height: 0; }
.columnas.una { grid-template-columns: 1fr; }

.columna { display: flex; flex-direction: column; min-height: 0; }
.col-head { display: flex; align-items: center; gap: .5rem; padding: 0 .2rem .55rem; }
.col-head h2 { font-size: .78rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; color: var(--muted); }
.cuenta {
    font-size: .72rem; font-weight: 700; font-variant-numeric: tabular-nums;
    background: var(--shade); color: var(--text); padding: .1rem .45rem; border-radius: 4px;
}

.col-cuerpo { flex: 1; overflow-y: auto; display: flex; flex-direction: column; gap: .7rem; padding-bottom: 1rem; }
.col-vacia { color: var(--muted); font-size: .84rem; text-align: center; padding: 1.6rem 0; }
</style>
