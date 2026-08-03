import { defineStore } from 'pinia';
import { api, setToken, hasToken } from './api';
import { leerIdentidad } from './bateria';

// Cada cuanto se pregunta. Con la pestana delante, 3 s; oculta, 15 s — una
// tablet en un armario no tiene que castigar al servidor.
const CADA = 3000;
const CADA_OCULTA = 15000;
// Ante error, el intervalo se estira y vuelve a 3 s al primer acierto.
const ESPERAS_DE_ERROR = [3000, 6000, 12000, 30000];
// A partir de aqui la pantalla deja de ser de fiar y lo dice en rojo.
const SIN_RESPUESTA_ALARMANTE = 15000;

/** La llave de una comanda: una orden puede tener dos, una por area. */
export const llaveDe = (fila) => `${fila.order_id}:${fila.area}`;

export const usePantalla = defineStore('kds', {
    state: () => ({
        pantalla: hasToken() ? 'tablero' : 'alta',
        dispositivo: JSON.parse(localStorage.getItem('kds_device') ?? 'null'),
        puesto: JSON.parse(localStorage.getItem('kds_outlet') ?? 'null'),

        comandas: [],
        etag: null,
        // Lo que la tablet ha movido y el servidor todavia no ha confirmado.
        // NO es una bandeja de salida como la del POS: una venta es un hecho
        // local que el servidor debe acabar aceptando, pero un estado de
        // cocina es una verdad COMPARTIDA y viva entre varias tablets.
        // Sincronizar veinte minutos despues un «marque lista» resucitaria
        // un estado viejo en todas las demas pantallas.
        enVuelo: {},

        ultimaRespuesta: null,
        ahora: Date.now(),
        // La diferencia entre el reloj del servidor y el de esta tablet. Una
        // tablet barata se desfasa, y sin esto pintaria esperas absurdas.
        desfase: 0,

        online: navigator.onLine,
        fallos: 0,
        error: null,
        ocupado: false,
        silencio: localStorage.getItem('kds_silencio') === 'si',
        buscando: false,
        resultados: null,
    }),

    getters: {
        // La hora del SERVIDOR segun esta tablet.
        reloj: (state) => state.ahora + state.desfase,

        frescura: (state) => state.ultimaRespuesta === null
            ? null
            : Math.max(0, Math.round((state.ahora - state.ultimaRespuesta) / 1000)),

        // Una pantalla congelada que parece viva es peor que una caida: el
        // cocinero cree que no hay pedidos.
        aCiegas: (state) => state.ultimaRespuesta !== null
            && state.ahora - state.ultimaRespuesta > SIN_RESPUESTA_ALARMANTE,

        // El estado que la pantalla ENSEÑA: el optimista si hay uno en vuelo,
        // el del servidor si no.
        estadoDe: (state) => (fila) => state.enVuelo[llaveDe(fila)]?.to ?? fila.status,

        columnas() {
            const cajones = { pending: [], in_progress: [], ready: [] };

            for (const fila of this.comandas) {
                const estado = this.estadoDe(fila);
                if (cajones[estado]) cajones[estado].push(fila);
            }

            return cajones;
        },
    },

    actions: {
        fallar(error) {
            this.error = error?.message ?? 'Algo salio mal.';

            // 401 no es un error de red que se reintenta: la tablet dejo de
            // estar autorizada (revocada, puesto cerrado, evento terminado).
            // Se borra el token y vuelve a la pantalla de alta.
            if (error?.status === 401) {
                setToken(null);
                localStorage.removeItem('kds_device');
                localStorage.removeItem('kds_outlet');
                this.dispositivo = null;
                this.puesto = null;
                this.comandas = [];
                this.etag = null;
                this.pantalla = 'alta';
            }
        },

        async enrolar({ codigo, pin, nombre, area }) {
            if (this.ocupado) return;
            this.ocupado = true;
            this.error = null;
            try {
                const data = await api.enrolar({
                    codigo, pin, device_name: nombre, area: area || null,
                    // Quien es este aparato, para que el servidor le devuelva
                    // SU fila si ya estuvo colgado en este puesto en vez de
                    // fabricarle otra. Va aqui y en ningun otro sitio: el
                    // sondeo ya va firmado con el token.
                    //
                    // No abre nada por su cuenta. El codigo y el PIN de arriba
                    // siguen siendo lo unico que deja entrar; esto solo decide
                    // en que fila se escribe una vez han pasado.
                    device_identity: leerIdentidad(),
                });
                setToken(data.token);
                this.dispositivo = data.device;
                this.puesto = { ...data.outlet, vendor: data.vendor, event: data.event };
                localStorage.setItem('kds_device', JSON.stringify(this.dispositivo));
                localStorage.setItem('kds_outlet', JSON.stringify(this.puesto));
                this.pantalla = 'tablero';
                await this.refrescar();
            } catch (error) {
                this.fallar(error);
            } finally {
                this.ocupado = false;
            }
        },

        async refrescar() {
            try {
                const data = await api.comandas(this.etag);
                this.ultimaRespuesta = Date.now();
                this.fallos = 0;

                if (data.sinCambios) return;

                if (data.server_time) {
                    this.desfase = Date.parse(data.server_time) - Date.now();
                }

                const antes = new Set(this.comandas.map(llaveDe));
                const llegadas = (data.tickets ?? []).filter(
                    (fila) => !antes.has(llaveDe(fila)) && fila.status === 'pending',
                );

                this.comandas = data.tickets ?? [];
                this.etag = data.etag ?? null;

                // Lo que el servidor confirma deja de estar en vuelo. Si el
                // servidor dice otra cosa, gana el servidor: alguien lo movio
                // desde otra tablet.
                for (const fila of this.comandas) {
                    const vuelo = this.enVuelo[llaveDe(fila)];
                    if (vuelo && vuelo.to === fila.status) delete this.enVuelo[llaveDe(fila)];
                }

                // El primer vistazo tras arrancar NO suena: si no, entrar por
                // la manana dispararia veinte avisos de golpe.
                if (antes.size > 0 && llegadas.length > 0) this.avisar();
            } catch (error) {
                if (error?.status === 401) return this.fallar(error);
                this.fallos += 1;
                // Sin red no se grita: la franja de frescura ya lo cuenta.
                if (error?.status) this.error = error.message;
            }
        },

        /**
         * Mover una comanda. La tarjeta se mueve YA y se revierte si el
         * servidor dice que no. El `from` viaja para que el servidor pueda
         * rechazar el movimiento de quien tenia una pantalla vieja: sin eso,
         * la cocinera marca LISTA y el ayudante, con datos de hace tres
         * segundos, lo deshace sin que nadie se entere.
         */
        async avanzar(fila, destino) {
            const llave = llaveDe(fila);
            if (this.enVuelo[llave]) return;

            const desde = this.estadoDe(fila);
            this.enVuelo[llave] = { to: destino, from: desde };

            try {
                const data = await api.avanzar(fila.order_id, fila.area, desde, destino);
                delete this.enVuelo[llave];
                this.aplicar(llave, data.ticket);
                // El ETag guardado ya no vale: forzar el proximo vistazo.
                this.etag = null;
            } catch (error) {
                delete this.enVuelo[llave];

                // 409: otro se adelanto. El cuerpo trae la fila VIGENTE, asi
                // que la pantalla se corrige al instante en vez de esperar al
                // siguiente sondeo.
                if (error?.status === 409 && error.data?.ticket) {
                    this.aplicar(llave, error.data.ticket);
                    this.error = 'Alguien la movio antes: mira el estado nuevo.';
                    this.etag = null;
                    return;
                }

                this.fallar(error);
            }
        },

        /**
         * Mete lo que devuelve el servidor sobre la fila que ya estaba. El
         * servidor solo devuelve la PARTE que cambia (estado y sellos de
         * hora), no la tarjeta entera: el resto —lineas, cliente, numero— se
         * conserva de la que ya se estaba pintando.
         */
        aplicar(llave, fila) {
            if (!fila?.order_id) return;
            const i = this.comandas.findIndex((f) => llaveDe(f) === llave);
            if (i !== -1) this.comandas[i] = { ...this.comandas[i], ...fila };
        },

        async buscar(q) {
            if (!q.trim()) { this.resultados = null; return; }
            this.buscando = true;
            try {
                const data = await api.buscar(q.trim());
                this.resultados = data.results ?? [];
            } catch (error) {
                this.fallar(error);
                this.resultados = [];
            } finally {
                this.buscando = false;
            }
        },

        alternarSilencio() {
            this.silencio = !this.silencio;
            localStorage.setItem('kds_silencio', this.silencio ? 'si' : 'no');
        },

        // Un pitido corto y una vibracion. El navegador solo deja sonar tras
        // un gesto del usuario: el boton de enrolar es ese gesto.
        avisar() {
            if (this.silencio) return;
            navigator.vibrate?.([120, 60, 120]);
            try {
                const ctx = new (window.AudioContext ?? window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const vol = ctx.createGain();
                osc.frequency.value = 880;
                vol.gain.value = 0.08;
                osc.connect(vol).connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.18);
            } catch { /* sin audio, la vibracion y el color bastan */ }
        },

        async salir() {
            try { await api.salir(); } catch { /* el token muere igual */ }
            setToken(null);
            localStorage.removeItem('kds_device');
            localStorage.removeItem('kds_outlet');
            this.dispositivo = null;
            this.puesto = null;
            this.comandas = [];
            this.pantalla = 'alta';
        },

        /** El intervalo del sondeo, que se estira solo cuando algo falla. */
        proximaEspera() {
            if (this.fallos > 0) {
                return ESPERAS_DE_ERROR[Math.min(this.fallos - 1, ESPERAS_DE_ERROR.length - 1)];
            }

            return document.visibilityState === 'hidden' ? CADA_OCULTA : CADA;
        },
    },
});
