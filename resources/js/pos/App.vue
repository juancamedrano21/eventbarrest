<script setup>
import { usePos } from './store';
import LoginScreen from './components/LoginScreen.vue';
import TillScreen from './components/TillScreen.vue';
import SaleScreen from './components/SaleScreen.vue';

const pos = usePos();
</script>

<template>
    <div class="pos-app">
        <header v-if="pos.screen !== 'login'" class="topbar">
            <strong>POS</strong>
            <span class="badge" :class="pos.online ? 'ok' : 'off'">
                {{ pos.online ? 'En linea' : 'Sin senal' }}
            </span>
            <span v-if="pos.pending > 0" class="badge warn">{{ pos.pending }} por sincronizar</span>
            <button class="ghost" @click="pos.logout()">Salir</button>
        </header>
        <p v-if="pos.error" class="error" @click="pos.error = null">{{ pos.error }}</p>
        <LoginScreen v-if="pos.screen === 'login'" />
        <TillScreen v-else-if="pos.screen === 'till'" />
        <SaleScreen v-else />
    </div>
</template>

<style>
* { box-sizing: border-box; margin: 0; }
body { background: #0f172a; color: #e2e8f0; font-family: system-ui, sans-serif; }
.pos-app { min-height: 100vh; display: flex; flex-direction: column; }
.topbar { display: flex; align-items: center; gap: .75rem; padding: .75rem 1rem; background: #1e293b; }
.topbar button.ghost { margin-left: auto; background: none; border: 1px solid #475569; color: #94a3b8; border-radius: .5rem; padding: .35rem .75rem; }
.badge { font-size: .75rem; padding: .2rem .6rem; border-radius: 999px; }
.badge.ok { background: #14532d; color: #86efac; }
.badge.off { background: #7f1d1d; color: #fecaca; }
.badge.warn { background: #78350f; color: #fcd34d; }
.error { background: #7f1d1d; color: #fecaca; padding: .6rem 1rem; cursor: pointer; }
.screen { padding: 1rem; max-width: 640px; width: 100%; margin: 0 auto; }
.field { display: block; margin-bottom: .9rem; }
.field span { display: block; font-size: .8rem; color: #94a3b8; margin-bottom: .25rem; }
input, select { width: 100%; padding: .7rem .8rem; border-radius: .6rem; border: 1px solid #334155; background: #1e293b; color: #e2e8f0; font-size: 1rem; }
button.primary { width: 100%; padding: .85rem; border: 0; border-radius: .6rem; background: #0284c7; color: white; font-size: 1.05rem; font-weight: 600; }
button.primary:disabled { opacity: .5; }
h2 { margin: .5rem 0 1rem; }
</style>
