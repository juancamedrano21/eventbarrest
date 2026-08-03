<script setup>
import { computed, ref } from 'vue';
import { usePantalla } from '../store';

const props = defineProps({ fila: { type: Object, required: true } });

const pantalla = usePantalla();

const estado = computed(() => pantalla.estadoDe(props.fila));
const enVuelo = computed(() => Boolean(pantalla.enVuelo[`${props.fila.order_id}:${props.fila.area}`]));

const SIGUIENTE = {
    pending: { a: 'in_progress', texto: 'Empezar' },
    in_progress: { a: 'ready', texto: 'Marcar lista' },
};
const ANTERIOR = { in_progress: 'pending', ready: 'in_progress' };

const siguiente = computed(() => SIGUIENTE[estado.value] ?? null);

/** Segundos transcurridos según el reloj del SERVIDOR, no el de la tablet. */
function desde(marca) {
    return marca ? Math.max(0, Math.round((pantalla.reloj - Date.parse(marca)) / 1000)) : null;
}

// Minutos y segundos mientras eso signifique algo. Pasada la hora, «187:12»
// no se lee de un vistazo y además ya no importan los segundos: lo que hay
// que ver es que ese pedido lleva ahí media noche.
function mmss(s) {
    if (s === null) return '—';
    if (s >= 3600) return `${Math.floor(s / 3600)} h ${Math.floor((s % 3600) / 60)} min`;

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

// Lo que el cliente lleva esperando: desde que PAGÓ, no desde que su pedido
// llegó al servidor. El servidor ya resuelve cuál de los dos relojes manda.
const espera = computed(() => desde(props.fila.waiting_since ?? props.fila.paid_at));
// Lo que lleva en cocina: desde que la comanda apareció aquí. Es lo único que
// la cocina controla, y por eso es lo que decide el color.
const enCocina = computed(() => desde(props.fila.paid_at));

const urgencia = computed(() => {
    const s = enCocina.value ?? 0;
    if (estado.value === 'ready') return 'lista';

    return s > 600 ? 'mal' : s > 300 ? 'medio' : 'bien';
});

// Cuánto tardó la venta en llegar. Sin esta chapa el cocinero recibe una
// comanda «nueva» de hace ocho minutos, ve al cliente furioso y no entiende
// nada. Quién es «tarde» lo decide el servidor; aquí solo se cuentan los
// minutos que hay que enseñar.
const retraso = computed(() => {
    if (!props.fila.late || !props.fila.device_sold_at) return null;

    return Math.round((Date.parse(props.fila.paid_at) - Date.parse(props.fila.device_sold_at)) / 60000);
});

const AREAS = { bar: 'Barra', kitchen: 'Cocina' };
const ESTADOS = { pending: 'Pendiente', in_progress: 'En proceso', ready: 'Lista' };

// Lo destructivo pide una pulsación larga: con la pantalla grasienta, un
// toque suelto en cualquier sitio movería comandas solo.
const pulsando = ref(false);
let cronometro = null;

function empezarPulsacion() {
    if (!ANTERIOR[estado.value]) return;
    pulsando.value = true;
    cronometro = setTimeout(() => {
        pulsando.value = false;
        navigator.vibrate?.(40);
        pantalla.avanzar(props.fila, ANTERIOR[estado.value]);
    }, 600);
}

function soltarPulsacion() {
    pulsando.value = false;
    clearTimeout(cronometro);
}
</script>

<template>
    <article class="comanda" :class="['u-' + urgencia, { volando: enVuelo }]">
        <header class="c-head">
            <strong class="c-num">{{ fila.number }}</strong>
            <span class="c-area" :class="'a-' + fila.area">{{ AREAS[fila.area] ?? fila.area }}</span>
        </header>

        <p v-if="fila.customer_name" class="c-cliente">{{ fila.customer_name }}</p>

        <p v-if="fila.refunded_cents > 0" class="c-devuelta">
            DEVUELTA · confirma antes de entregar
        </p>

        <ul class="c-lineas">
            <li v-for="(linea, i) in fila.lines ?? []" :key="i">
                <span class="c-cant">{{ linea.quantity }}×</span>
                <span class="c-plato">
                    {{ linea.product_name }}
                    <em v-if="linea.notes" class="c-nota">{{ linea.notes }}</em>
                </span>
            </li>
        </ul>

        <div class="c-relojes">
            <span><small>Espera del cliente</small>{{ mmss(espera) }}</span>
            <span><small>En cocina</small>{{ mmss(enCocina) }}</span>
        </div>

        <div class="c-chapas">
            <span v-if="retraso" class="c-chapa c-tarde">LLEGÓ TARDE +{{ retraso }} min</span>
            <span v-if="fila.sibling_status" class="c-chapa c-hermana">
                {{ fila.area === 'bar' ? 'Cocina' : 'Barra' }}: {{ ESTADOS[fila.sibling_status] }}
            </span>
        </div>

        <button v-if="siguiente" class="c-accion" :disabled="enVuelo"
            @click="pantalla.avanzar(fila, siguiente.a)">
            {{ siguiente.texto }}
        </button>
        <p v-else class="c-final">Lista {{ mmss(desde(fila.ready_at)) }}</p>

        <button v-if="ANTERIOR[estado]" class="c-atras" :class="{ cargando: pulsando }"
            @pointerdown="empezarPulsacion()" @pointerup="soltarPulsacion()"
            @pointerleave="soltarPulsacion()" @pointercancel="soltarPulsacion()">
            Mantén para volver atrás
        </button>
    </article>
</template>

<style scoped>
.comanda {
    background: var(--panel); border: 1px solid var(--line-strong);
    border-left: 6px solid var(--line-strong);
    border-radius: 4px; padding: .9rem 1rem 1rem;
    display: flex; flex-direction: column; gap: .55rem;
    user-select: none; touch-action: manipulation;
}
/* El color por antigüedad se calcula sobre el reloj DE COCINA, que es lo que
   la cocina controla — no sobre la espera del cliente, que incluye el
   retraso de la red y culparía a quien cocina de un problema de wifi. */
.u-bien { border-left-color: var(--ok); }
.u-medio { border-left-color: var(--warn); }
.u-mal { border-left-color: var(--bad); }
.u-lista { border-left-color: var(--accent-2); opacity: .82; }
.volando { opacity: .55; }

.c-head { display: flex; align-items: baseline; justify-content: space-between; gap: .5rem; }
.c-num { font-size: 2.6rem; line-height: 1; font-variant-numeric: tabular-nums; letter-spacing: -.02em; }
.c-area {
    font-size: .68rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase;
    padding: .2rem .5rem; border-radius: 4px;
}
.a-bar { background: color-mix(in srgb, var(--warn) 18%, transparent); color: var(--warn); }
.a-kitchen { background: color-mix(in srgb, var(--accent-2) 18%, transparent); color: var(--accent-2); }

.c-cliente { font-size: 1.25rem; font-weight: 600; }

.c-devuelta {
    background: color-mix(in srgb, var(--bad) 16%, transparent); color: var(--bad);
    font-size: .78rem; font-weight: 700; letter-spacing: .03em;
    padding: .38rem .55rem; border-radius: 4px;
}

.c-lineas { list-style: none; display: flex; flex-direction: column; gap: .35rem; }
.c-lineas li { display: flex; gap: .5rem; font-size: 1.35rem; line-height: 1.2; }
.c-cant { font-weight: 700; min-width: 2.1rem; font-variant-numeric: tabular-nums; }
.c-plato { flex: 1; }
/* La nota es lo único de esta tarjeta que cambia lo que hay que hacer. */
.c-nota {
    display: block; font-style: normal; font-size: .95rem; font-weight: 700;
    text-transform: uppercase; color: var(--warn); letter-spacing: .02em;
}

.c-relojes { display: flex; gap: 1.2rem; font-variant-numeric: tabular-nums; }
.c-relojes span { display: flex; flex-direction: column; font-size: 1.05rem; font-weight: 600; }
.c-relojes small { font-size: .64rem; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }

.c-chapas { display: flex; flex-wrap: wrap; gap: .35rem; }
.c-chapa { font-size: .68rem; font-weight: 700; padding: .2rem .5rem; border-radius: 4px; letter-spacing: .03em; }
.c-tarde { background: color-mix(in srgb, var(--bad) 16%, transparent); color: var(--bad); }
.c-hermana { background: var(--shade); color: var(--muted); }

/* Un solo botón primario a todo el ancho: el pulgar de alguien de pie. */
.c-accion {
    min-height: 88px; border-radius: 4px; margin-top: .2rem;
    background: var(--accent); color: var(--on-accent);
    font-size: 1.2rem; font-weight: 700;
}
.c-accion:active { transform: scale(.985); }
.c-accion:disabled { opacity: .5; }
.c-final { margin-top: .2rem; font-size: .84rem; color: var(--muted); text-align: center; }

.c-atras {
    font-size: .74rem; color: var(--muted); padding: .45rem;
    border: 1px dashed var(--line-strong); border-radius: 4px;
    position: relative; overflow: hidden;
}
.c-atras.cargando::after {
    content: ''; position: absolute; inset: 0; transform-origin: left;
    background: color-mix(in srgb, var(--bad) 24%, transparent);
    animation: cargar .6s linear forwards;
}
@keyframes cargar { from { transform: scaleX(0); } to { transform: scaleX(1); } }
</style>
