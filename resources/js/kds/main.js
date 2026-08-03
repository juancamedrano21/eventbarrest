import { createApp } from 'vue';
import { createPinia } from 'pinia';
// El mismo sistema visual del POS: variables, overlays, botones y campos.
import '../../css/device-theme.css';
import App from './App.vue';
import { usePantalla } from './store';
import { hasToken } from './api';

const app = createApp(App);
app.use(createPinia());
app.mount('#kds');

const pantalla = usePantalla();

// El reloj de la pantalla. Uno solo para toda la app: veinte tarjetas con su
// propio setInterval serian veinte temporizadores por nada.
setInterval(() => { pantalla.ahora = Date.now(); }, 1000);

// El sondeo se reprograma DESPUES de cada respuesta, no en un intervalo fijo:
// asi una peticion lenta no se solapa con la siguiente, y el retroceso ante
// error se aplica solo. El jitter evita que veinte tablets golpeen a la vez.
let temporizador = null;

function programar(ms) {
    clearTimeout(temporizador);
    temporizador = setTimeout(vuelta, ms + Math.random() * 500);
}

async function vuelta() {
    if (hasToken()) await pantalla.refrescar();
    programar(pantalla.proximaEspera());
}

if (hasToken()) {
    vuelta();
}

// Volver a mirar la pantalla, o recuperar la senal, pregunta YA: esperar tres
// segundos con la tablet en la mano ya se nota.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && hasToken()) programar(0);
});

window.addEventListener('online', () => {
    pantalla.online = true;
    if (hasToken()) programar(0);
});

window.addEventListener('offline', () => {
    pantalla.online = false;
});

// Que la pantalla no se apague en mitad del servicio. Se vuelve a pedir al
// volver de segundo plano: el navegador suelta el bloqueo al ocultarse.
async function mantenerEncendida() {
    try {
        await navigator.wakeLock?.request('screen');
    } catch { /* sin permiso o sin soporte: no es critico */ }
}

mantenerEncendida();
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') mantenerEncendida();
});
