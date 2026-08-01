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

window.addEventListener('online', () => {
    pos.online = true;
    pos.syncOutbox();
});
window.addEventListener('offline', () => {
    pos.online = false;
});
setInterval(() => pos.syncOutbox(), 15000);
