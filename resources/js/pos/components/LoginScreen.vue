<script setup>
import { ref } from 'vue';
import { usePos } from '../store';

const pos = usePos();
const username = ref('');
const password = ref('');
</script>

<template>
    <div class="login">
        <form class="login-card" @submit.prevent="pos.login(username, password)">
            <div class="login-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/><path d="M2 7h20"/><path d="M22 7v3a2 2 0 0 1-2 2a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 16 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 12 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 8 12a2.7 2.7 0 0 1-1.59-.63.7.7 0 0 0-.82 0A2.7 2.7 0 0 1 4 12a2 2 0 0 1-2-2V7"/></svg>
            </div>
            <h1>Punto de venta</h1>
            <p class="login-sub">Entra con tu usuario de cajero.</p>

            <label class="field"><span>Usuario</span>
                <input v-model="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required>
            </label>
            <label class="field"><span>Contrasena</span>
                <input v-model="password" type="password" autocomplete="current-password" required>
            </label>

            <button type="submit" class="btn-primary" :disabled="pos.busy">
                {{ pos.busy ? 'Entrando...' : 'Entrar' }}
            </button>

            <p class="login-note">Funciona sin senal: las ventas se guardan en el dispositivo y sincronizan solas.</p>
        </form>
    </div>
</template>

<style scoped>
.login {
    flex: 1; display: grid; place-items: center; padding: 1.2rem;
    position: relative; overflow: hidden;
}
.login-card {
    position: relative; width: min(400px, 100%);
    background: var(--panel); border: 1px solid var(--line-strong);
    border-radius: 4px; padding: 2rem 1.7rem;
   
}
.login-mark {
    display: grid; place-items: center; width: 52px; height: 52px; border-radius: 4px;
    background: linear-gradient(135deg, #0ea5e9, #0369a1);
   
    color: white; margin-bottom: 1.1rem;
}
.login-mark svg { width: 26px; height: 26px; }
h1 { font-size: 1.35rem; margin-bottom: .25rem; }
.login-sub { color: var(--muted); font-size: .88rem; margin-bottom: 1.4rem; }
.login-note {
    margin-top: 1.1rem; color: var(--muted); font-size: .76rem;
    text-align: center; line-height: 1.45;
}
</style>
