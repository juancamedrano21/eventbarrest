<script setup>
import { computed, ref } from 'vue';
import { usePantalla } from './store';
import EnrolarScreen from './components/EnrolarScreen.vue';
import TableroScreen from './components/TableroScreen.vue';
import BuscarOverlay from './components/BuscarOverlay.vue';

const pantalla = usePantalla();
const buscando = ref(false);

// 24 horas: «21:40» se lee de un vistazo y «09:40 p. m.» no. Y es la hora
// del SERVIDOR, que es contra la que se cuentan todas las esperas: una
// tablet con el reloj corrido no puede desmentir a la pantalla.
const hora = computed(() => new Date(pantalla.reloj)
    .toLocaleTimeString('es-DO', { hour: '2-digit', minute: '2-digit', hour12: false }));

// El estado de la red se cuenta con la FRESCURA, no con navigator.onLine:
// una tablet puede estar «en línea» conectada a un router que no llega a
// ningún sitio, y eso engaña más que no decir nada.
const red = computed(() => {
    if (pantalla.aCiegas) return { clase: 'pill-bad', texto: 'SIN CONEXIÓN' };
    if (pantalla.fallos > 0) return { clase: 'pill-warn', texto: 'Reintentando' };

    return { clase: 'pill-ok', texto: 'Al día' };
});
</script>

<template>
    <div class="kds-app">
        <template v-if="pantalla.pantalla === 'alta'">
            <EnrolarScreen />
        </template>

        <template v-else>
            <header class="barra">
                <div class="b-puesto">
                    <strong>{{ pantalla.puesto?.name ?? 'Comandas' }}</strong>
                    <span>{{ pantalla.puesto?.vendor?.name }}</span>
                </div>

                <div class="b-centro">
                    <span class="pill" :class="red.clase">
                        <span class="dot" :class="pantalla.aCiegas ? 'dot-off' : 'dot-ok'"></span>
                        {{ red.texto }}
                    </span>
                    <!-- El reloj de frescura va SIEMPRE visible: es la única
                         forma de distinguir «no hay pedidos» de «esta pantalla
                         lleva rato mintiendo». -->
                    <span class="b-frescura">
                        {{ pantalla.frescura === null ? 'esperando…' : `hace ${pantalla.frescura} s` }}
                    </span>
                </div>

                <div class="b-acciones">
                    <span class="b-hora">{{ hora }}</span>
                    <button class="icon-btn" :title="pantalla.silencio ? 'Activar aviso' : 'Silenciar'"
                        @click="pantalla.alternarSilencio()">
                        <svg v-if="pantalla.silencio" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 2l20 20"/><path d="M10.3 5.3A6 6 0 0 1 18 11v3"/><path d="M6 8v3a6 6 0 0 1-2 4.5h12"/><path d="M10 20h4"/></svg>
                        <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0v3a6 6 0 0 0 2 4.5H4A6 6 0 0 0 6 11Z"/><path d="M10 20h4"/></svg>
                    </button>
                    <button class="btn-soft" @click="buscando = true">¿Y lo mío?</button>
                    <button class="icon-btn" title="Sacar esta tablet" @click="pantalla.salir()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/></svg>
                    </button>
                </div>
            </header>

            <p v-if="pantalla.aCiegas" class="alarma">
                SIN CONEXIÓN — puede haber pedidos que no ves
            </p>

            <TableroScreen />
        </template>

        <BuscarOverlay v-if="buscando" @cerrar="buscando = false; pantalla.resultados = null" />

        <Transition name="toast">
            <div v-if="pantalla.error" class="error-toast" @click="pantalla.error = null">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                <span>{{ pantalla.error }}</span>
            </div>
        </Transition>
    </div>
</template>

<style>
.kds-app { min-height: 100vh; min-height: 100dvh; display: flex; flex-direction: column; }

.barra {
    display: flex; align-items: center; gap: .8rem;
    padding: .5rem .8rem; min-height: 44px;
    background: var(--panel); border-bottom: 1px solid var(--line);
    position: sticky; top: 0; z-index: 10;
}
.b-puesto { display: flex; flex-direction: column; line-height: 1.15; min-width: 0; }
.b-puesto strong { font-size: .92rem; }
.b-puesto span { font-size: .72rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.b-centro { flex: 1; display: flex; align-items: center; justify-content: center; gap: .5rem; flex-wrap: wrap; }
.b-frescura { font-size: .72rem; color: var(--muted); font-variant-numeric: tabular-nums; }

.b-acciones { display: flex; align-items: center; gap: .45rem; }
.b-hora { font-size: .82rem; color: var(--muted); font-variant-numeric: tabular-nums; }
@media (max-width: 760px) { .b-hora { display: none; } }

.alarma {
    background: var(--bad); color: #fff; text-align: center;
    font-size: .82rem; font-weight: 700; letter-spacing: .04em; padding: .4rem;
}
</style>
