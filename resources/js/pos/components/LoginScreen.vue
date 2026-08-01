<script setup>
import { ref } from 'vue';
import { usePos } from '../store';

const pos = usePos();
const username = ref('');
const password = ref('');
</script>

<template>
    <div class="screen">
        <h2>Entrar al punto de venta</h2>
        <label class="field"><span>Usuario</span>
            <input v-model="username" type="text" autocomplete="username" autocapitalize="none" spellcheck="false">
        </label>
        <label class="field"><span>Contrasena</span>
            <input v-model="password" type="password" autocomplete="current-password">
        </label>
        <button class="primary" :disabled="pos.busy" @click="pos.login(username, password)">
            {{ pos.busy ? 'Entrando...' : 'Entrar' }}
        </button>
    </div>
</template>
