import { createApp } from 'vue';
import { createPinia } from 'pinia';
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
setInterval(() => {
    if (hasToken()) pos.syncOutbox();
}, 15000);
