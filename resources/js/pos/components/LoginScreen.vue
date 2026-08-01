<script setup>
import { ref } from 'vue';
import { usePos } from '../store';

const pos = usePos();
const email = ref('');
const password = ref('');
</script>

<template>
    <div class="screen">
        <h2>Entrar al punto de venta</h2>
        <label class="field"><span>Correo</span>
            <input v-model="email" type="email" autocomplete="username" inputmode="email">
        </label>
        <label class="field"><span>Contrasena</span>
            <input v-model="password" type="password" autocomplete="current-password">
        </label>
        <button class="primary" :disabled="pos.busy" @click="pos.login(email, password)">
            {{ pos.busy ? 'Entrando...' : 'Entrar' }}
        </button>
    </div>
</template>
