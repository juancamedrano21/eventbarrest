<script setup>
import { computed, ref } from 'vue';
import { usePantalla } from '../store';

const pantalla = usePantalla();

const codigo = ref('');
const pin = ref('');
const nombre = ref('Cocina 1');
const area = ref('kitchen');

const areas = [
    { value: 'kitchen', label: 'Cocina' },
    { value: 'bar', label: 'Barra' },
    { value: '', label: 'Las dos' },
];

// El codigo se teclea de un papel pegado en la nevera: se acepta con guiones,
// en minusculas o con espacios de mas, se limpia aqui, y se devuelve al campo
// ya partido en dos mitades para que se lea de un vistazo.
const codigoLimpio = computed(() => codigo.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase().slice(0, 8));

const codigoVisible = computed({
    get: () => codigoLimpio.value.replace(/^(.{4})(.+)$/, '$1-$2'),
    set: (valor) => { codigo.value = valor; },
});

const listo = computed(() => codigoLimpio.value.length === 8 && pin.value.length === 6);

// Teclado propio y no el del sistema: el del movil tapa media tablet, sale y
// entra con cada campo, y con guantes no se acierta en teclas de 30 px.
const teclas = ['1', '2', '3', '4', '5', '6', '7', '8', '9', 'borrar', '0', 'entrar'];

function pulsar(tecla) {
    if (tecla === 'borrar') {
        pin.value = pin.value.slice(0, -1);
    } else if (tecla === 'entrar') {
        entrar();
    } else if (pin.value.length < 6) {
        pin.value += tecla;
    }
}

function entrar() {
    if (!listo.value || pantalla.ocupado) return;
    pantalla.enrolar({
        codigo: codigoLimpio.value,
        pin: pin.value,
        nombre: nombre.value.trim() || 'Pantalla de cocina',
        area: area.value,
    });
}
</script>

<template>
    <div class="alta">
        <div class="alta-card">
            <h1 class="alta-title">Pantalla de comandas</h1>
            <p class="alta-sub">
                Se da de alta una sola vez. Después esta tablet queda dentro
                hasta que alguien la saque desde el panel.
            </p>

            <label class="field"><span>Código del comercio</span>
                <input v-model="codigoVisible" type="text" inputmode="text" autocapitalize="characters"
                    autocomplete="off" spellcheck="false" maxlength="9" placeholder="XXXX-XXXX"
                    class="alta-code">
            </label>

            <div class="field">
                <span>PIN del puesto</span>
                <div class="pin-dots">
                    <span v-for="i in 6" :key="i" class="pin-dot" :class="{ on: pin.length >= i }"></span>
                </div>
            </div>

            <div class="teclado">
                <button v-for="tecla in teclas" :key="tecla" type="button" class="tecla"
                    :class="{ 'tecla-accion': tecla === 'borrar', 'tecla-ok': tecla === 'entrar' }"
                    :disabled="tecla === 'entrar' && (!listo || pantalla.ocupado)"
                    @click="pulsar(tecla)">
                    <template v-if="tecla === 'borrar'">⌫</template>
                    <template v-else-if="tecla === 'entrar'">Entrar</template>
                    <template v-else>{{ tecla }}</template>
                </button>
            </div>

            <label class="field"><span>Nombre de esta tablet</span>
                <input v-model="nombre" type="text" maxlength="60" placeholder="Cocina 1" autocomplete="off">
            </label>

            <div class="field">
                <span>Qué muestra</span>
                <div class="segmentado">
                    <button v-for="opcion in areas" :key="opcion.value" type="button"
                        :class="{ activo: area === opcion.value }" @click="area = opcion.value">
                        {{ opcion.label }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.alta { min-height: 100dvh; display: grid; place-items: center; padding: 1.5rem; }
.alta-card { width: min(440px, 100%); }
.alta-title { font-size: 1.4rem; margin-bottom: .35rem; }
.alta-sub { color: var(--muted); font-size: .88rem; margin-bottom: 1.6rem; }

.alta-code { font-size: 1.6rem; letter-spacing: .28em; text-align: center; font-variant-numeric: tabular-nums; }

.pin-dots { display: flex; gap: .6rem; justify-content: center; padding: .7rem 0; }
.pin-dot {
    width: 18px; height: 18px; border-radius: 4px;
    border: 2px solid var(--line-strong); background: transparent; transition: all .12s;
}
.pin-dot.on { background: var(--accent-2); border-color: var(--accent-2); }

/* Teclas de 96 px: con guantes, con las manos mojadas y mirando de reojo. */
.teclado { display: grid; grid-template-columns: repeat(3, 1fr); gap: .5rem; margin-bottom: 1.4rem; }
.tecla {
    height: 96px; border-radius: 4px; border: 1px solid var(--line-strong);
    background: var(--panel); color: var(--text);
    font-size: 1.9rem; font-weight: 600; font-variant-numeric: tabular-nums;
    touch-action: manipulation; user-select: none;
}
.tecla:active { background: var(--panel-2); transform: scale(.97); }
.tecla-accion { font-size: 1.5rem; color: var(--muted); }
.tecla-ok { font-size: 1.05rem; background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
.tecla-ok:disabled { opacity: .4; }

.segmentado { display: flex; gap: .4rem; }
.segmentado button {
    flex: 1; padding: .8rem .4rem; border-radius: 4px;
    border: 1px solid var(--line-strong); color: var(--muted); font-weight: 600;
}
.segmentado button.activo { background: var(--accent); color: var(--on-accent); border-color: var(--accent); }
</style>
