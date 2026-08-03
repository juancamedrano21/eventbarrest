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
// Cada cuanto un tablero VACIO se vuelve a pedir entero, sin If-None-Match.
// Un minuto es lo mas que puede durar una pantalla en blanco equivocada, y
// cuesta una peticion por minuto solo cuando no hay nada que cocinar.
const VERIFICAR_VACIO = 60000;

/** La llave de una comanda: una orden puede tener dos, una por area. */
export const llaveDe = (fila) => `${fila.order_id}:${fila.area}`;

export const usePantalla = defineStore('kds', {
    state: () => ({
        pantalla: hasToken() ? 'tablero' : 'alta',
        dispositivo: JSON.parse(localStorage.getItem('kds_device') ?? 'null'),
        puesto: JSON.parse(localStorage.getItem('kds_outlet') ?? 'null'),

        comandas: [],
        // EL ETag SOLO SE GUARDA JUNTO AL CUERPO QUE SE PINTO CON EL. Es la
        // regla de la que depende toda esta pantalla: el ETag es la promesa
        // «ya tengo esto», y el servidor la cree —contesta 304 y no manda
        // nada—. Guardar uno cuyo cuerpo no se llego a pintar deja la cocina
        // a oscuras indefinidamente y sin un solo error. Ver refrescar().
        etag: null,
        // Hay UN sondeo en el aire como mucho, y esto lo sostiene.
        sondeando: false,
        // Que sesion de tablet vive ahora. Sube al enrolar, al salir y al
        // 401: una respuesta que llegue con un numero viejo habla de una
        // tablet que ya no es esta y se tira.
        sesion: 0,
        // Cuando se pinto por ultima vez un tablero COMPLETO (no un 304).
        ultimoSnapshot: 0,
        // Sube cada vez que la tablet invalida su ETag a proposito (mover una
        // tarjeta). Sirve para que un sondeo que salio ANTES de ese
        // movimiento no vuelva a pintar el tablero de antes.
        revision: 0,
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
                this.olvidarElTablero();
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
                // Una tablet que acaba de entrar no arrastra NADA de la
                // anterior, y el ETag es lo unico que se colaba: sin esto, el
                // primer sondeo de la sesion nueva podia salir preguntando
                // «¿ha cambiado algo desde aquello?» por un tablero que esta
                // pantalla no ha pintado.
                this.olvidarElTablero();
                await this.refrescar();
            } catch (error) {
                this.fallar(error);
            } finally {
                this.ocupado = false;
            }
        },

        /**
         * El sondeo del tablero. Lee entera la nota del ETag de arriba antes
         * de tocar nada de aqui.
         */
        async refrescar() {
            // UN SOLO SONDEO EN EL AIRE. `programar(0)` de main.js dispara al
            // volver de segundo plano y al recuperar la señal, y sin esto se
            // solapaba con el que ya estaba viajando: dos respuestas pedidas
            // con ETags distintos, y la que llegaba tarde repintaba el
            // tablero de hace un ciclo. La pantalla rebobinaba tres segundos
            // cada vez que alguien la miraba.
            if (this.sondeando) return;

            this.sondeando = true;
            // Que sesion y que revision hicieron la pregunta. Las dos se
            // comparan al volver: entre la ida y la vuelta cabe un «sacar la
            // tablet» y cabe un toque en una tarjeta.
            const sesion = this.sesion;
            const revision = this.revision;

            try {
                const data = await api.comandas(this.etag);

                // Esta respuesta habla de una tablet que ya no es esta.
                if (sesion !== this.sesion) return;

                if (data.sinCambios) {
                    this.aceptada();
                    this.verificarTableroVacio();

                    return;
                }

                // EL CUERPO SE VALIDA ANTES DE CREERSELO, Y ANTES DE CONTARLO
                // COMO RESPUESTA BUENA. Un 200 sin `tickets` no es un tablero
                // vacio, es una respuesta que no se pudo leer: el `?? []` que
                // habia aqui traducia «el servidor no me dijo las comandas»
                // por «no hay comandas» y encima archivaba el ETag del cuerpo
                // que nunca se pinto. Se trata como error para que ni las
                // comandas ni el ETag se muevan.
                if (!Array.isArray(data.tickets)) {
                    throw { status: 0, code: 'cuerpo_ilegible', message: 'La respuesta llego a medias.' };
                }

                this.aceptada();

                // Mientras esto viajaba, alguien movio una tarjeta y anulo el
                // ETag a proposito (ver avanzar()). Este cuerpo es de ANTES de
                // ese movimiento: pintarlo desharia en pantalla lo que la
                // cocinera acaba de tocar. Se tira, y como avanzar() dejo el
                // ETag en null el siguiente sondeo trae el tablero entero.
                if (revision !== this.revision) {
                    return;
                }

                if (data.server_time) {
                    this.desfase = Date.parse(data.server_time) - Date.now();
                }

                const antes = new Set(this.comandas.map(llaveDe));
                const llegadas = data.tickets.filter(
                    (fila) => !antes.has(llaveDe(fila)) && fila.status === 'pending',
                );

                // Los dos juntos y desde el MISMO cuerpo validado: eso es todo
                // lo que hace falta para que un 304 nunca mienta.
                this.comandas = data.tickets;
                this.etag = data.etag ?? null;
                this.ultimoSnapshot = Date.now();

                // Lo que el servidor confirma deja de estar en vuelo. Si el
                // servidor dice otra cosa, gana el servidor: alguien lo movio
                // desde otra tablet.
                for (const fila of this.comandas) {
                    const vuelo = this.enVuelo[llaveDe(fila)];
                    if (vuelo && vuelo.to === fila.status) delete this.enVuelo[llaveDe(fila)];
                }

                // Una cocina no se vacia de golpe. Que el tablero pase de
                // tener comandas a no tener ninguna en un solo sondeo es lo
                // bastante raro como para no darlo por bueno sin preguntar
                // otra vez sin ETag: si el vacio es cierto sale gratis, y si
                // no lo es se arregla en el siguiente sondeo en vez de durar
                // hasta la proxima venta.
                if (antes.size > 0 && this.comandas.length === 0) {
                    this.etag = null;
                }

                // El primer vistazo tras arrancar NO suena: si no, entrar por
                // la manana dispararia veinte avisos de golpe.
                if (antes.size > 0 && llegadas.length > 0) this.avisar();
            } catch (error) {
                if (sesion !== this.sesion) return;
                if (error?.status === 401) return this.fallar(error);
                this.fallos += 1;
                // Sin red no se grita: la franja de frescura ya lo cuenta.
                if (error?.status) this.error = error.message;
            } finally {
                // Solo si sigue siendo la misma sesion: si murio, el candado
                // ya lo solto olvidarElTablero() y puede haber otro sondeo
                // legitimo en el aire al que no le toca abrirselo.
                if (sesion === this.sesion) this.sondeando = false;
            }
        },

        /**
         * Se hablo con el servidor y lo que dijo sirve. Va DESPUES de mirar el
         * contenido a proposito: cuando estas dos lineas se ponian nada mas
         * volver del `await`, una respuesta inservible contaba como respuesta
         * buena, la franja de frescura marcaba 0 s y `aCiegas` —la unica
         * alarma que hay— no llegaba a saltar nunca.
         */
        aceptada() {
            this.ultimaRespuesta = Date.now();
            this.fallos = 0;
        },

        /**
         * Un tablero vacio no se cree jamas solo porque llegue un 304.
         *
         * Un 304 dice «sigue igual que el ETag que me mandaste», y si por lo
         * que sea el ETag guardado no se corresponde con lo que hay pintado,
         * la pantalla se queda en cero para siempre sin quejarse — que es el
         * peor fallo posible en una cocina. Asi que de vez en cuando el vacio
         * se comprueba pidiendo el tablero ENTERO. Solo aplica cuando no hay
         * ninguna comanda: con el tablero lleno el 304 se cree sin dudar,
         * porque una pantalla que enseña de mas se ve y una que enseña de
         * menos no.
         */
        verificarTableroVacio() {
            if (this.comandas.length > 0) return;
            if (Date.now() - this.ultimoSnapshot < VERIFICAR_VACIO) return;

            this.etag = null;
        },

        /**
         * Se acabo esta tablet: fuera el tablero y, sobre todo, FUERA EL ETag.
         *
         * Olvidarse del ETag aqui dejaba la pantalla en blanco de forma
         * garantizada, no por mala suerte. Al salir, `comandas` se vaciaba y
         * el ETag se quedaba vivo; al volver a enrolar la MISMA tablet en el
         * MISMO puesto con el MISMO nombre, el cuerpo que calcula el servidor
         * es identico byte a byte, y con el el ETag: el primer sondeo recibia
         * un 304 —correctisimo— sobre un tablero vacio y la cocina no veia
         * una sola comanda hasta la siguiente venta. Sacar la tablet y volver
         * a entrar es justo lo que hace quien ve la pantalla rara.
         */
        olvidarElTablero() {
            this.comandas = [];
            this.etag = null;
            this.enVuelo = {};
            this.ultimaRespuesta = null;
            this.ultimoSnapshot = 0;
            this.resultados = null;
            // Lo que siga viajando pertenece a la sesion que acaba de morir.
            this.sesion += 1;
            this.sondeando = false;
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
                this.invalidarEtag();
            } catch (error) {
                delete this.enVuelo[llave];

                // 409: otro se adelanto. El cuerpo trae la fila VIGENTE, asi
                // que la pantalla se corrige al instante en vez de esperar al
                // siguiente sondeo.
                if (error?.status === 409 && error.data?.ticket) {
                    this.aplicar(llave, error.data.ticket);
                    this.error = 'Alguien la movio antes: mira el estado nuevo.';
                    this.invalidarEtag();

                    return;
                }

                this.fallar(error);
            }
        },

        /**
         * El ETag guardado ya no vale: el proximo sondeo pide el tablero
         * entero. Va con contador porque ademas hay que poder distinguir a la
         * vuelta si el ETag se anulo DESPUES de haber preguntado — un `null`
         * a secas no dice cuando se puso, y comparar el valor no distingue el
         * null de antes del null de ahora.
         */
        invalidarEtag() {
            this.etag = null;
            this.revision += 1;
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
            this.olvidarElTablero();
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
