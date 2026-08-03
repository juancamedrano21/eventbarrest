import { createApp } from 'vue';
import { createPinia } from 'pinia';
// El sistema visual que el POS comparte con la pantalla de cocina.
import '../../css/device-theme.css';
import App from './App.vue';
import { usePos } from './store';
import { hasToken } from './api';

const app = createApp(App);
app.use(createPinia());
app.mount('#pos');

const pos = usePos();

if (hasToken()) {
    pos.arrive();
}

// Volver la senal revalida el estado (la caja pudo cerrar desde el panel)
// y luego vacia la bandeja. El intervalo solo trabaja con sesion iniciada.
window.addEventListener('online', () => {
    pos.online = true;
    if (hasToken()) pos.arrive();
});
window.addEventListener('offline', () => {
    pos.online = false;
});
// Con la bandeja vacia, 15 s; con algo dentro, 5 s. El KDS no puede ir mas
// rapido que esto: una venta cobrada sin senal no existe para la cocina
// hasta que sale de aqui, y es este tramo —no el sondeo de la tablet— el
// que hace esperar al cliente. Es la unica linea que acorta esa espera.
let proximoVuelo = null;

function programarSync() {
    clearTimeout(proximoVuelo);
    proximoVuelo = setTimeout(async () => {
        if (hasToken()) await pos.syncOutbox();
        programarSync();
    }, pos.pending > 0 || pos.errored > 0 ? 5000 : 15000);
}

programarSync();
