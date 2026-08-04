import { defineStore } from 'pinia';
import { api, setToken, hasToken, PLAZO_BASE, PLAZO_TOPE } from './api';
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

// Cuantas veces se dobla la paciencia antes de volver a empezar. Con el suelo
// en 8 s son 8 → 16 → 32 (que el techo recorta a 24): tres intentos bastan para
// que un enlace de 11 s deje de ser invisible, y no mas porque el cuarto ya
// solo alargaria el candado del sondeo sin preguntar nada nuevo.
const ESCALONES_DE_PLAZO = 2;

// Cuantas veces se manda un movimiento antes de darlo por no confirmado. DOS,
// y el segundo es el que averigua la verdad: si el primero llego y solo se
// perdio la respuesta, el servidor contesta 409 con la fila vigente ya en el
// destino y la tarjeta se queda donde la cocinera la puso. Ver empujar().
const INTENTOS_DE_MOVIMIENTO = 2;

/** La llave de una comanda: una orden puede tener dos, una por area. */
export const llaveDe = (fila) => `${fila.order_id}:${fila.area}`;

// El mando para cortar el sondeo que viaja ahora mismo, y EL SELLO DE LA RED
// POR LA QUE SALIO. Viven FUERA del store a proposito: no son datos de la
// pantalla, no se pintan, no sobreviven a nada y no tienen por que pasar por el
// sistema reactivo. Solo puede haber un juego porque solo puede haber un sondeo
// en el aire (ver el candado de refrescar()).
//
// El sello es la mitad que faltaba para poder decidir si el sondeo en curso
// sigue vivo: sin el, lo unico que quedaba para adivinarlo era el contador de
// fallos, y ese no distingue «salio por una conexion que ya no existe» de «va
// lento y va a llegar». Ver abandonarSondeo().
let corteDelSondeo = null;
let redDelSondeo = 0;

// EL TECHO DE ABANDONOS, Y ES LA PIEZA QUE SOSTIENE TODA ESTA PANTALLA.
//
// Abandonar un sondeo es una OPTIMIZACION: ahorra los segundos que quedaban de
// una peticion que salio por una conexion muerta. Que el tablero se pinte es la
// CORRECCION. Cuando las dos chocan gana pintar, y aqui chocan de verdad: el
// sello de red dice si ESTE sondeo esta muerto, pero no dice nada de cuantos
// van ya, y sin contar eso los eventos del sistema tenian barra libre. En
// cuanto llegaban mas seguidos que el viaje de ida y vuelta del enlace —un
// `offline`+`online` cada 10 s en un enlace sano de 11 s— cada sondeo moria un
// segundo antes de llegar y NINGUNO se completaba jamas, con el servidor
// contestando bien todas las peticiones: tablero en blanco, o la comanda nueva
// que nadie de la cocina llega a leer. Y no es hipotetico: esta linea ha
// reabierto el mismo grave tres veces, cada vez con un disparador distinto
// («cualquier online», «online precedido de offline»), porque el error nunca
// estuvo en el disparador sino en que no habia techo.
//
// El techo es este: TRAS UN ABANDONO, EL SIGUIENTE SONDEO ES INTOCABLE. Como
// mucho se abandona uno de cada dos, asi que ninguna secuencia de eventos de
// red —por densa que sea, venga de donde venga— puede impedir que el tablero
// complete un sondeo contra un servidor sano. Se eligio esto y no un contador
// con ventana porque la garantia se ve de un vistazo y no depende de calibrar
// ningun numero: el sondeo intocable corre su plazo entero, y de eso ya se
// encarga la escalera de plazoDeRed() que aprende lo que cuesta la casa.
let sondeoIntocable = false;
let intocarElProximo = false;

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
        // DESDE CUANDO SE CUENTA EL SILENCIO CUANDO TODAVIA NO HA HABIDO NI UNA
        // RESPUESTA BUENA. Sin esto, la unica alarma de la pantalla no existia
        // justo cuando mas falta hace: `aCiegas` exigia `ultimaRespuesta`, que
        // arranca en null y vuelve a null al re-enrolar y al 401 — o sea, una
        // tablet que arranca contra un servidor caido, o la que acaban de sacar
        // y volver a meter (que es lo primero que hace quien ve la pantalla
        // rara), se pasaba la noche entera con la pastilla en ambar, la franja
        // roja sin salir y las columnas diciendo «Nada por aqui». La pantalla
        // afirmando que la cocina esta tranquila. Cargar la pagina es un
        // instante conocido y sirve de origen: si en 15 s desde el arranque no
        // ha entrado nada, esta pantalla no es de fiar y lo dice.
        arranque: Date.now(),
        // Lo que tardo la ultima respuesta BUENA, en milisegundos. Es la unica
        // medida real que hay del enlace y de ella sale la paciencia del
        // proximo sondeo. Ver plazoDeRed().
        latencia: null,
        // Plazos agotados SEGUIDOS. Cuenta solo los abortos por tiempo, no los
        // demas fallos: un `sin_red` dice que no hay a quien preguntar y
        // esperar mas no compra nada; un plazo agotado dice que quiza si habia
        // alguien y no le dio tiempo.
        abortos: 0,
        ahora: Date.now(),
        // La diferencia entre el reloj del servidor y el de esta tablet. Una
        // tablet barata se desfasa, y sin esto pintaria esperas absurdas.
        desfase: 0,

        online: navigator.onLine,
        // CUANTAS VECES SE HA SABIDO QUE LA CONEXION ES OTRA. No es un numero
        // para pintar: es el sello con el que sale marcado cada sondeo, y lo
        // unico que permite saber a la vuelta de un evento `online` si la
        // peticion que viaja salio por la conexion de antes o por esta. Sube
        // cuando el sistema dice que la red se fue, y otra vez cuando dice que
        // volvio DESPUES de haberse ido — no cuando lo dice sin mas, porque el
        // `online` de Android salta por gusto y un evento que no cambia nada no
        // puede invalidar una peticion que iba bien. Ver abandonarSondeo().
        cambiosDeRed: 0,
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
        //
        // NO HAY EXCEPCION PARA «TODAVIA NO HE HABLADO CON NADIE». Ese caso no
        // es mas benigno que los demas, es el PEOR: una tablet sin ninguna
        // respuesta buena no tiene absolutamente nada que enseñar y sin embargo
        // enseña tres columnas vacias con toda la cara de estar al dia. Cuando
        // no hay `ultimaRespuesta` se cuenta desde el arranque de esta sesion.
        aCiegas: (state) => state.ahora - (state.ultimaRespuesta ?? state.arranque)
            > SIN_RESPUESTA_ALARMANTE,

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
            //
            // ESTE CANDADO SOLO ES SEGURO PORQUE LA PETICION LLEVA PLAZO (ver
            // plazoDeRed() y PLAZO_TOPE en api.js). Un candado que se abre en
            // el `finally` de un `await` que puede no volver nunca no es un
            // candado, es una pantalla congelada para el resto de la noche: no
            // se pide otro sondeo, `despertar()` corta por esta misma bandera y
            // no hay forma de salir sin recargar. Si algun dia se quita el
            // plazo de api.js, hay que quitar tambien esta guardia — o poner
            // otra cosa que garantice que el `await` de abajo termina siempre.
            if (this.sondeando) return;

            this.sondeando = true;
            // Que sesion y que revision hicieron la pregunta. Las dos se
            // comparan al volver: entre la ida y la vuelta cabe un «sacar la
            // tablet» y cabe un toque en una tarjeta.
            const sesion = this.sesion;
            const revision = this.revision;
            const salida = Date.now();
            corteDelSondeo = new AbortController();
            // Por que conexion sale. Lo que se compara al llegar un `online`.
            redDelSondeo = this.cambiosDeRed;
            // Y si este es el sondeo que sale JUSTO DESPUES de un abandono.
            // Ese no se toca pase lo que pase: es el que garantiza que la
            // cadena de abandonos no puede ser infinita. Se consume aqui, al
            // salir, y no al terminar, porque lo que hay que proteger es este
            // viaje entero, no el hueco entre dos viajes.
            sondeoIntocable = intocarElProximo;
            intocarElProximo = false;

            try {
                const data = await api.comandas(this.etag, this.plazoDeRed(), corteDelSondeo);

                // Esta respuesta habla de una tablet que ya no es esta.
                if (sesion !== this.sesion) return;

                if (data.sinCambios) {
                    this.aceptada(Date.now() - salida, false);
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

                this.aceptada(Date.now() - salida, true);

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

                // El plazo agotado se cuenta APARTE de los demas fallos, y esa
                // es la unica forma que tiene la pantalla de distinguir «lento»
                // de «muerto»: la diferencia no se ve en una peticion, se ve en
                // la SIGUIENTE, dandole mas cuerda. Cualquier otro final —el
                // servidor contesto un error, la red dijo que no, el cuerpo
                // llego roto— demuestra que la espera no era el problema y
                // devuelve la paciencia al suelo.
                //
                // EL CORTE QUE PEDIMOS NOSOTROS NO CUENTA NI PARA UN LADO NI
                // PARA EL OTRO, y por eso viaja con codigo propio. No es que el
                // servidor no llegara a tiempo —no le dimos tiempo—, asi que
                // sumarlo a `abortos` ensanchaba el plazo del siguiente sondeo
                // sin ninguna medida detras: mas ventana abierta, mas
                // probabilidad de que el siguiente evento tambien lo mate, y la
                // pantalla en blanco por realimentacion. Y devolverlo al suelo
                // tampoco vale: un corte deliberado no demuestra que el enlace
                // sea rapido. De lo que no se ha medido, no se aprende.
                if (error?.code !== 'sondeo_abandonado') {
                    this.abortos = error?.code === 'plazo_agotado' ? this.abortos + 1 : 0;
                }

                // Y cae donde tiene que caer: como FALLO. No pasa por
                // `aceptada()`, asi que `ultimaRespuesta` se queda donde estaba
                // y la franja de `aCiegas` puede subir; y no toca el ETag, asi
                // que la peticion que se abandono no deja al store creyendo que
                // ya tiene un cuerpo que nunca llego a pintar.
                this.fallos += 1;
                // Lo que no llego a ser una respuesta no se grita: ni la red
                // caida ni el plazo agotado. La pastilla, la franja roja y el
                // reloj de frescura ya lo cuentan, y el toast de App.vue solo
                // se quita tocandolo — en una tablet colgada de la pared eso es
                // un cartel encima de las tarjetas hasta que alguien suba.
                if (error?.status) this.error = error.message;
            } finally {
                // Solo si sigue siendo la misma sesion: si murio, el candado
                // ya lo solto olvidarElTablero() y puede haber otro sondeo
                // legitimo en el aire al que no le toca abrirselo.
                if (sesion === this.sesion) this.sondeando = false;
            }
        },

        /**
         * LA RED SE FUE. El sistema dice que este aparato se quedo sin
         * conexion, y eso es lo mas parecido a un hecho que hay aqui: a partir
         * de ahora, cualquier peticion que estuviera viajando salio por una
         * conexion que ya no existe.
         */
        redSeFue() {
            this.online = false;
            this.cambiosDeRed += 1;
        },

        /**
         * LA RED VOLVIO, y hay que decidir si el sondeo en curso sirve.
         *
         * El evento `online` NO significa siempre lo mismo, y confundir sus dos
         * significados es lo que apagaba la pantalla:
         *
         * - Si nos constaba que no habia red, este evento cuenta un CAMBIO: la
         *   conexion de antes se murio y esta es otra. Lo que siguiera viajando
         *   es un cadaver y se corta.
         * - Si ya nos constaba que habia red, no cuenta nada. El `online` de
         *   Android salta varias veces por gusto, tambien con todo funcionando,
         *   y creerselo cortaba sondeos SANOS: en un enlace de 11 s —lento pero
         *   vivo, que es justo el que este rescate existe para salvar— una
         *   tanda de esos eventos no dejaba entrar ni una sola respuesta y el
         *   tablero se quedaba en blanco sin salir de ahi solo. Un evento que no
         *   cambia nada no puede invalidar una peticion que iba a llegar.
         */
        redVolvio() {
            if (!this.online) this.cambiosDeRed += 1;

            this.online = true;
            this.abandonarSondeo();
        },

        /**
         * Cortar el sondeo que viaja ahora mismo, si salio por una conexion que
         * ya no existe.
         *
         * Sin este corte, la señal de que el wifi esta bueno otra vez se queda
         * esperando a que a la peticion en curso se le acabe el plazo, y ese
         * plazo es largo justo en este caso, asi que el tablero sigue viejo
         * hasta medio minuto con la red ya buena.
         *
         * LO QUE DECIDE ES EL SELLO DE RED, NO EL CONTADOR DE FALLOS. Se probo
         * con `fallos > 0` y estaba al reves de lo que hace falta, por los dos
         * lados a la vez:
         *
         * - Un corte de red CORTO —el caso normal, el que esto viene a
         *   resolver— empieza SIEMPRE con `fallos === 0`, porque el sondeo
         *   anterior habia ido bien. O sea que el rescate no se activaba nunca
         *   cuando tocaba.
         * - Y en un enlace lento pero SANO, el primer sondeo se aborta por
         *   plazo mientras se mide la casa: `fallos > 0` a los ocho segundos de
         *   encender la tablet, el freno desarmado, y cualquier `online`
         *   espurio matando el sondeo siguiente. Tablero en blanco para siempre.
         *
         * Lo que de verdad separa «esta peticion salio por una conexion que ya
         * no existe» de «esta peticion va lenta pero llegara» no es cuantas
         * veces hemos fallado: es si la conexion CAMBIO despues de que la
         * peticion saliera. Eso es lo que compara el sello.
         *
         * Y CORTAR NO VACIA EL TABLERO: el corte sale por el `catch` de
         * refrescar(), que no toca ni `comandas` ni `etag` (ver 11f5279). Una
         * respuesta abandonada es una respuesta que no tenemos, no un tablero
         * vacio.
         *
         * PERO EL SELLO SOLO DICE SI ESTE SONDEO ESTA MUERTO, NO CUANTOS
         * LLEVAMOS. Por eso hay ademas un techo, y es la garantia de la que
         * cuelga la pantalla entera: ver la nota de `sondeoIntocable` arriba.
         * Sin el, una racha de eventos de red bastaba para que ningun sondeo
         * llegara nunca a completarse.
         */
        abandonarSondeo() {
            if (!this.sondeando) return;
            // Salio por esta misma conexion: va lenta, pero es la que hay.
            if (redDelSondeo === this.cambiosDeRed) return;
            // EL TECHO. Este sondeo salio justo detras de un abandono, asi que
            // se le deja terminar aunque su conexion tambien haya cambiado.
            // Perder la optimizacion cuesta unos segundos de tablero viejo;
            // perder el techo cuesta el tablero.
            if (sondeoIntocable) return;

            // Al que venga detras no se le toca. Se apunta ANTES de cortar
            // porque cortar hace que el `await` de refrescar() reviente y el
            // siguiente sondeo puede salir de inmediato (main.js programa a 0
            // al volver la red): la deuda tiene que estar puesta ya.
            intocarElProximo = true;
            corteDelSondeo?.abort();
        },

        /**
         * Se hablo con el servidor y lo que dijo sirve. Va DESPUES de mirar el
         * contenido a proposito: cuando estas dos lineas se ponian nada mas
         * volver del `await`, una respuesta inservible contaba como respuesta
         * buena, la franja de frescura marcaba 0 s y `aCiegas` —la unica
         * alarma que hay— no llegaba a saltar nunca.
         *
         * Y es el unico sitio donde se APRENDE lo que cuesta este enlace. Se
         * guarda la ultima medida y no la peor ni una media: un enlace que se
         * arregla tiene que poder volver a los plazos cortos en un solo ciclo,
         * y con un maximo pegajoso una mala racha de las nueve dejaria a la
         * tablet esperando 24 s por respuesta el resto de la noche.
         *
         * PERO NO TODAS LAS RESPUESTAS MIDEN LO MISMO, Y ESA ES LA CORRECCION
         * IMPORTANTE DE AQUI. Un 304 son unas cabeceras y nada mas —esa es la
         * razon de ser del ETag—, mientras que lo caro es el tablero entero. En
         * una cocina tranquila casi todos los sondeos son 304, asi que
         * aprendiendo del ULTIMO sin mirar que traia, cada 304 devolvia la
         * medida al suelo y dejaba el plazo corto para el unico sondeo que
         * importa: el que trae la comanda nueva. Ese se abortaba una o dos veces
         * antes de entrar, y la cocina veia la comanda 10 s mas tarde que sin
         * plazo ninguno — el arreglo convertido en la averia, otra vez.
         *
         * Asi que el cuerpo entero manda: fija la medida, hacia arriba y hacia
         * abajo. Un 304 solo puede SUBIRLA (si tarda mas de lo que costo el
         * ultimo tablero, el enlace ha empeorado y el tablero costaria al menos
         * eso), nunca bajarla, porque de lo barato no se aprende cuanto cuesta
         * lo caro. Y con el techo de plazoDeRed() puesto, equivocarse por
         * paciente cuesta como mucho un candado de 24 s; equivocarse por
         * impaciente cuesta la comanda.
         *
         * @param  {number}  tardanza   Milisegundos entre la pregunta y esta
         *                              respuesta, medidos por el store:
         *                              incluyen el viaje entero, no solo el
         *                              `fetch`.
         * @param  {boolean} conCuerpo  Si esta respuesta traia el tablero
         *                              entero (200) o solo cabeceras (304).
         */
        aceptada(tardanza, conCuerpo) {
            this.ultimaRespuesta = Date.now();
            this.fallos = 0;
            this.abortos = 0;
            if (!Number.isFinite(tardanza)) return;

            this.latencia = conCuerpo || this.latencia === null
                ? tardanza
                : Math.max(this.latencia, tardanza);
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
            // El origen del silencio se mueve aqui y no en otro sitio: al
            // volver a entrar, esta pantalla no ha hablado con nadie TODAVIA,
            // igual que recien cargada, y la cuenta de los 15 s de `aCiegas`
            // vuelve a empezar. Sin esto, re-enrolar dejaria la franja roja
            // puesta desde el primer segundo de una sesion que aun no ha tenido
            // tiempo de fallar.
            this.arranque = Date.now();
            // Lo aprendido del enlace muere con la sesion: puede que la tablet
            // este cambiando de wifi, o de puesto, o de recinto.
            this.latencia = null;
            this.abortos = 0;
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
         *
         * UN MOVIMIENTO NO SE PARECE EN NADA A UN SONDEO Y POR ESO NO SE TRATA
         * IGUAL. El sondeo perdido se repite solo dentro de tres segundos y
         * nadie se entera; el movimiento perdido no lo repite nadie —esta
         * accion era el unico camino— y encima la cocinera cree que lo hizo. Un
         * plazo pensado para el sondeo, aplicado tal cual aqui, convertia en
         * fallo rojo un movimiento que el servidor SI habia aplicado, y con el
         * enlace lento por los dos lados lo perdia entero.
         */
        async avanzar(fila, destino) {
            const llave = llaveDe(fila);
            if (this.enVuelo[llave]) return;

            const desde = this.estadoDe(fila);
            this.enVuelo[llave] = { to: destino, from: desde };

            try {
                const ticket = await this.empujar(fila, desde, destino);
                delete this.enVuelo[llave];
                this.aplicar(llave, ticket);
                this.invalidarEtag();
            } catch (error) {
                delete this.enVuelo[llave];

                // 409: otro se adelanto Y ADEMAS la dejo en otro sitio. El
                // cuerpo trae la fila VIGENTE, asi que la pantalla se corrige
                // al instante en vez de esperar al siguiente sondeo. (El 409
                // que deja la comanda justo donde se queria no llega hasta
                // aqui: lo resuelve empujar() como el exito que es.)
                if (error?.status === 409 && error.data?.ticket) {
                    this.aplicar(llave, error.data.ticket);

                    // Y SE AVISA SIEMPRE QUE SE LLEGA HASTA AQUI, porque llegar
                    // hasta aqui ya significa que la comanda esta donde
                    // nosotros no la pusimos: el 409 que la deja en NUESTRO
                    // destino no baja, lo resuelve empujar() como el exito que
                    // es. Se probo a callarlo cuando el 409 venia de un
                    // reintento y eso tapaba conflictos ajenos de verdad, que
                    // es peor: la tarjeta salta de columna delante de la
                    // cocinera y nadie le dice que otra persona anda en su
                    // comanda. Ver empujar().
                    this.error = 'Alguien la movio antes: mira el estado nuevo.';

                    this.invalidarEtag();

                    return;
                }

                // LA RED CAIDA SI SE SABE, Y NO SE PARECE A UN PLAZO AGOTADO.
                // `sin_red` es el `fetch` rechazando en el acto: la peticion no
                // llego a salir de la tablet, o sea que el movimiento NO se
                // aplico y no hay nada que confirmar. Mandarla a esperar un
                // tablero que no puede llegar —no hay red con la que traerlo—
                // era dejarla mirando una tarjeta que ya ha vuelto a su columna
                // sin saber si tiene que repetir el gesto. Aqui si tiene que
                // repetirlo, cuando vuelva la red, y se le dice.
                if (error?.code === 'sin_red') {
                    this.error = 'Sin conexion con el servidor: la comanda no se movio.';

                    return;
                }

                // NI LLEGO A HABER RESPUESTA, ASI QUE NO SE SABE SI EL
                // MOVIMIENTO SE APLICO — y decirle a la cocinera que fallo
                // seria mentir la mitad de las veces: volveria a tocar una
                // tarjeta que ya estaba movida. Se le dice lo unico cierto, y
                // se tira el ETag para que el proximo sondeo traiga el tablero
                // entero y la tarjeta acabe donde el servidor diga.
                if (!error?.status) {
                    this.error = 'No se pudo confirmar el movimiento: el tablero dira como quedo.';
                    this.invalidarEtag();

                    return;
                }

                this.fallar(error);
            }
        },

        /**
         * El POST de mover, CON REINTENTO. Devuelve la fila vigente.
         *
         * Reintentar un movimiento suena a duplicarlo y aqui no lo es, porque
         * el servidor no controla por intento sino POR ESTADO: el cuerpo lleva
         * de donde venia la comanda, y AdvanceKitchenTicket rechaza con 409
         * —trayendo dentro la fila vigente— todo lo que no salga de ahi. Asi
         * que repetir el mismo POST no puede duplicar el movimiento: o el
         * primero no llego y este lo aplica, o el primero SI llego y este
         * choca. Lo que NO se reintenta es un error del servidor con codigo
         * (404, 422, 401): eso no es una respuesta perdida, es un no.
         *
         * EL CHOQUE TIENE DOS FORMAS Y LAS SEPARA LO QUE DICE EL SERVIDOR, NO
         * EL NUMERO DE INTENTO. El 409 trae dentro la fila VIGENTE, y ahi esta
         * la respuesta: si esa fila ya esta en NUESTRO destino, el choque fue
         * contra nuestro propio POST perdido —llego, se aplico, y solo se
         * perdio la respuesta— y no hay nada que corregir ni que contar. Si
         * esta en cualquier otro sitio, la comanda esta donde nosotros no la
         * pusimos: eso es otra persona, y hay que decirlo.
         *
         * SE PROBO A MARCARLO POR LA POSICION DEL INTENTO (`intento > 0`) Y
         * ESTABA MAL. Un reintento no demuestra nada sobre quien movio la
         * tarjeta: la cocinera toca EN PROCESO, otra tablet la manda a LISTA
         * mientras nuestro primer POST se cuelga, y el reintento choca con un
         * 409 identico al del POST perdido. Marcarlo por posicion silenciaba
         * ese aviso, la tarjeta saltaba a LISTA delante de sus ojos y nadie le
         * decia por que — castigando ademas a la que trabaja bien, que perdia
         * la unica pista de que otra persona anda en su comanda.
         *
         * Cada intento va con el doble de paciencia que el anterior por la
         * misma razon que el sondeo: con el enlace lento en los dos sentidos,
         * repetir con el mismo plazo es repetir el mismo aborto.
         */
        async empujar(fila, desde, destino) {
            let ultimo = null;

            for (let intento = 0; intento < INTENTOS_DE_MOVIMIENTO; intento += 1) {
                try {
                    const data = await api.avanzar(
                        fila.order_id,
                        fila.area,
                        desde,
                        destino,
                        this.plazoDeRed() * 2 ** intento,
                    );

                    return data.ticket;
                } catch (error) {
                    // La comanda ya esta donde queriamos llevarla. Da igual si
                    // la puso nuestro intento anterior o la compañera de al
                    // lado: no hay nada que corregir y nada que avisar. Este es
                    // ademas el UNICO sitio donde consta que el choque fue
                    // contra nosotros mismos, y por eso no hace falta marcar
                    // nada aguas abajo: lo que sale de aqui es un exito.
                    if (error?.status === 409 && error.data?.ticket?.status === destino) {
                        return error.data.ticket;
                    }

                    if (error?.status) throw error;

                    ultimo = error;
                }
            }

            throw ultimo;
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
                const data = await api.buscar(q.trim(), this.plazoDeRed());
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

        /**
         * CUANTO SE ESPERA A UNA RESPUESTA. Es el numero del que depende que
         * una cocina con el wifi justo vea comandas o no vea ninguna.
         *
         * Sale de dos cosas y se queda con la mayor:
         *
         * 1. LO MEDIDO. El doble de lo que costo traer el ultimo TABLERO
         *    ENTERO, que es la respuesta cara y la unica que hay que llegar a
         *    tiempo de leer; un 304 mide otra cosa y por eso no baja esta
         *    medida (ver aceptada()). El doble y no lo justo porque una latencia
         *    no es una constante: si la ultima tardo 6 s, cortar la siguiente a
         *    los 6 s seria cortar la mitad de las que vienen. Y esto es lo que
         *    hace que un enlace lento pero SANO se estabilice en vez de morir:
         *    en cuanto un solo tablero entra, el plazo se ajusta a lo que cuesta
         *    ese enlace y los siguientes dejan de abortarse.
         * 2. LO ESCALADO. Cada plazo agotado seguido dobla la paciencia, y al
         *    tercero se vuelve a empezar: 8 → 16 → 24 → 8 → 16 → 24. Es la
         *    unica forma de descubrir a un servidor que contesta en 11 s cuando
         *    todavia no ha contestado ninguna vez —se pregunta con 8, con 16 y
         *    con 24, y la que entra deja medida la casa— y la escalera VUELVE a
         *    bajar porque si tres paciencias distintas no han traido nada, la
         *    hipotesis «es que va lento» ya se probo y fallo. Contra un servidor
         *    muerto eso deja dos de cada tres sondeos soltando el candado en 8 s
         *    en vez de tenerlo puesto 24 s para siempre, que es lo que le
         *    interesa a una pantalla que solo espera a que vuelva la red.
         *
         * El techo (PLAZO_TOPE) y el suelo (PLAZO_BASE) los pone api.js, que
         * ademas los vuelve a aplicar por su cuenta: aqui se acotan para que la
         * escalada no se dispare, y alli para que ningun llamador pueda saltarse
         * el limite.
         */
        plazoDeRed() {
            const aprendido = this.latencia === null ? PLAZO_BASE : this.latencia * 2;
            const escalado = PLAZO_BASE * 2 ** (this.abortos % (ESCALONES_DE_PLAZO + 1));

            return Math.min(Math.max(aprendido, escalado, PLAZO_BASE), PLAZO_TOPE);
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
