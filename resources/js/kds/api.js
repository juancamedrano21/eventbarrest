// Cliente de la API de comandas. Todo error llega como { status, code,
// message }: la app decide por CODIGO, nunca parseando mensajes.
//
// Las claves de almacenamiento son PROPIAS y no las del POS aunque compartan
// origen: si fueran las mismas, el logout del cajero mataria la sesion de la
// cocina en mitad del servicio.
import { leerBateria } from './bateria';

const BASE = '/api/kds';

// EL PLAZO NO ES UN NUMERO FIJO, Y ESTO ES LO IMPORTANTE DE TODO EL ARCHIVO.
//
// Un plazo fijo tiene DOS averias, no una, y son opuestas. La primera es no
// tenerlo: una peticion que no vuelve nunca congela la pantalla el resto de la
// noche (ver request()). La segunda es tenerlo clavado: cortar a los 8 s a un
// enlace que contesta en 11 —lento pero SANO— no lo degrada, LO APAGA. Todas
// las respuestas se abortan, ninguna llega jamas, el tablero no se pinta una
// sola vez y el retroceso lo empeora en vez de salvarlo, porque estira la
// pregunta a una cada 30 s. Esa cocina, sin plazo, veia comandas tarde; con un
// plazo clavado no ve ninguna. El arreglo convertido en la averia.
//
// Y «lento» y «muerto» NO se distinguen a priori: lo unico que los separa es
// que uno acaba contestando, y para saberlo hay que esperar. Asi que no se
// adivina, se MIDE — el plazo lo decide el store en plazoDeRed(), que sabe lo
// que tardo la ultima respuesta buena y cuantos plazos seguidos se han agotado.
// Aqui solo viven el suelo y el techo de esa cuenta.
//
// EL SUELO. Por debajo lo limita el ritmo del propio sondeo: con la pantalla
// delante se pregunta cada 3 s y el retroceso ante error empieza en otros 3 s,
// asi que un plazo de ese orden cortaria en masa respuestas que iban a llegar.
// 8 s sobra para el peor viaje creible en el wifi de un recinto (el tablero es
// una consulta sola y lo normal es que vuelva por debajo del segundo) y hace
// que el PRIMER cuelgue se note pronto: fallo mas reintento dentro de la
// ventana del primer aviso.
export const PLAZO_BASE = 8000;

// EL TECHO. Por encima de esto no hay enlace lento, hay averia: un tablero que
// tarda mas de 24 s en llegar es mas viejo que la propia alarma
// (SIN_RESPUESTA_ALARMANTE, 15 s en store.js), o sea que la pantalla ya esta en
// rojo mientras espera y quien la mira ya sabe que no puede fiarse. Se sigue
// esperando —tarde es mejor que nunca— pero no mas, porque cada segundo de mas
// es un segundo con el candado del sondeo puesto.
export const PLAZO_TOPE = 24000;

// El plazo aparte para leerse la bateria, que no es una peticion de red y no
// merece los 8 s de una: o el puente del APK contesta en el acto o no hay
// dato. Ver cabecerasDeBateria().
const PLAZO_BATERIA = 1000;

let token = localStorage.getItem('kds_token');

export function setToken(value) {
    token = value;
    if (value) {
        localStorage.setItem('kds_token', value);
    } else {
        localStorage.removeItem('kds_token');
    }
}

export function hasToken() {
    return Boolean(token);
}

/**
 * Un plazo agotado es un FALLO, no una respuesta.
 *
 * Sale con `status: 0` como el resto de lo que ni siquiera llego a ser una
 * respuesta HTTP, y eso es lo que importa aguas abajo: el store lo cuenta en
 * `fallos` —estira el retroceso y deja subir la franja de `aCiegas`— y no
 * toca ni las comandas ni el ETag. Un cuelgue NO puede parecerse a un 304.
 *
 * EL `code` ES LO QUE LO SEPARA DE `sin_red`, Y ESA SEPARACION TRABAJA. No es
 * para el registro: `plazo_agotado` significa «el servidor esta ahi y no le ha
 * dado tiempo», y es lo unico que autoriza al store a tener mas paciencia la
 * proxima vez (plazoDeRed / this.abortos). `sin_red` significa «no hay a quien
 * preguntar», y ahi esperar mas no compra nada.
 *
 * EL `message` NO SALE EN PANTALLA DURANTE EL SONDEO, A PROPOSITO. El toast de
 * App.vue es pegajoso —solo se va tocandolo— y una tablet colgada de una pared
 * a dos metros del suelo no tiene quien lo toque: un aviso por cada sondeo
 * acabaria tapando tarjetas. El sondeo ya se cuenta en la pastilla y en la
 * franja roja, que es lo que se ve de lejos. Este texto es para las acciones
 * que alguien acaba de pedir con el dedo y espera respuesta —buscar, entrar,
 * salir—, que llaman a fallar() sin condicion.
 */
const errorDePlazo = () => ({
    status: 0,
    code: 'plazo_agotado',
    message: 'El servidor no contesto a tiempo.',
});

/**
 * La peticion no se acabo: la cortamos NOSOTROS. Es otra cosa que un plazo
 * agotado y por eso tiene codigo propio.
 *
 * Un plazo agotado es una medida —«este servidor no contesta en N segundos»— y
 * el store la usa para tener mas paciencia la proxima vez. Un corte pedido
 * desde fuera no mide nada: no le dimos tiempo. Contandolos juntos, cada corte
 * ensanchaba el plazo del sondeo siguiente sin ningun motivo, la ventana en la
 * que un evento puede matarlo crecia sola, y una tanda de eventos dejaba la
 * pantalla en blanco por realimentacion. Ver el `catch` de refrescar().
 */
const errorDeAbandono = () => ({
    status: 0,
    code: 'sondeo_abandonado',
    message: 'Se dejo de esperar a una peticion que salio por otra conexion.',
});

/** Como acabo un intento que se aborto: por el reloj de aqui o por el llamador. */
const finDelIntento = (porPlazo) => (porPlazo.si ? errorDePlazo() : errorDeAbandono());

/**
 * TODA PETICION SALE CON PLAZO. NO QUITES ESTO NI LO CONVIERTAS EN UN `fetch`
 * PELADO.
 *
 * Un `fetch` sin `signal` puede esperar indefinidamente —un wifi que se va sin
 * cerrar la conexion, un proxy de recinto que acepta la conexion y no contesta
 * nunca—, y esa promesa que jamas resuelve congelaba la pantalla el resto de
 * la noche por DOS caminos a la vez:
 *
 * 1. `vuelta()` en main.js hacia `await pantalla.refrescar()` y reprogramaba
 *    DESPUES. Colgado el await, no habia mas vueltas: la cadena de
 *    temporizadores no se iba de vacio, se moria.
 * 2. El candado de reentrada del store (`sondeando`) solo se suelta cuando ese
 *    await termina. Puesto para que dos sondeos no se pisaran, era exactamente
 *    lo que impedia pedir otro — y `despertar()` mira el mismo candado, asi que
 *    ni volver a mirar la tablet ni recuperar la señal desatascaban nada.
 *
 * Resultado: cocina a oscuras, sin un solo error, hasta que alguien recargue.
 *
 * `AbortController` a mano y NO `AbortSignal.timeout()`, que es WebView 103+:
 * en la tablet barata de recinto con el WebView de fabrica seria `undefined`,
 * el `fetch` reventaria con un TypeError sincrono dentro del try y TODAS las
 * peticiones acabarian en `sin_red` permanente. El arreglo rompiendo justo lo
 * que viene a arreglar, y en la misma clase de aparato.
 *
 * CUANTO se espera lo decide QUIEN LLAMA, porque solo el sabe lo que cuesta
 * perder esta peticion: un sondeo perdido se repite en tres segundos, un alta
 * perdida deja la tablet fuera del servicio y hay alguien delante mirandola.
 * Lo que no decide nadie es salirse de [PLAZO_BASE, PLAZO_TOPE]: se acota aqui
 * para que ningun llamador —ni un numero aprendido que se desmadre— pueda
 * dejar una peticion sin plazo util ni esperando media noche.
 *
 * El `corte` se puede pasar de fuera para poder cancelar ANTES del plazo. El
 * unico que lo usa es el sondeo, y solo cuando el sistema avisa de que la red
 * volvio: la peticion que viajaba salio por una conexion que ya no existe, y
 * esperar a que se le acabe el tiempo son segundos de tablero viejo con el
 * wifi ya bueno. Cancelar es la unica forma legitima de soltar el candado
 * antes de hora — soltarlo SIN cancelar dejaria dos respuestas en el aire con
 * ETags distintos, que es justo lo que arreglo 11f5279.
 */
async function request(method, path, body = null, { headers = {}, plazo, corte = new AbortController() } = {}) {
    // QUIEN ABORTO: el reloj de aqui o el llamador. `signal.aborted` dice que
    // se aborto, no por que, y son dos averias distintas aguas abajo (ver
    // errorDeAbandono()). No se usa `signal.reason` a proposito: es WebView
    // 98+, la misma clase de aparato que ya descarto AbortSignal.timeout().
    // Un objeto de una linea vale y funciona en cualquier WebView con `fetch`.
    const porPlazo = { si: false };
    const reloj = setTimeout(() => { porPlazo.si = true; corte.abort(); }, acotarPlazo(plazo));

    try {
        return await enviar(corte.signal, method, path, body, headers, porPlazo);
    } finally {
        // EL PLAZO CUBRE LA PETICION ENTERA, CABECERAS Y CUERPO. `fetch`
        // resuelve en cuanto llegan las CABECERAS y el cuerpo viaja despues,
        // asi que cancelar el reloj nada mas volver del `fetch` dejaria sin
        // plazo justo el tramo que mas se corta en un wifi de festival: el
        // `response.json()` de abajo. Se cancela aqui, cuando ya no queda nada
        // que esperar.
        clearTimeout(reloj);
    }
}

/**
 * El plazo pedido, metido a la fuerza entre el suelo y el techo. Un `plazo`
 * que no sea un numero —nadie lo paso, o una cuenta que salio NaN— cae en el
 * suelo en vez de en `NaN`: un `setTimeout(fn, NaN)` dispara en el acto y
 * abortaria TODAS las peticiones al instante, que es la peor forma de fallar
 * que tiene este archivo.
 */
function acotarPlazo(plazo) {
    if (!Number.isFinite(plazo)) return PLAZO_BASE;

    return Math.min(Math.max(plazo, PLAZO_BASE), PLAZO_TOPE);
}

async function enviar(signal, method, path, body, extraHeaders, porPlazo = { si: true }) {
    let response;
    try {
        response = await fetch(BASE + path, {
            method,
            signal,
            // La cache del navegador NO participa en esto. La revalidacion la
            // lleva la app a mano con su If-None-Match, y un WebView que se
            // ponga a responder por su cuenta —o un proxy de wifi de festival
            // que guarde el snapshot de hace un rato— pintaria comandas viejas
            // sin que el servidor se entere. Aqui la unica cache es el ETag
            // que guarda el store, y es la que sabe lo que se pinto.
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
                ...extraHeaders,
            },
            body: body ? JSON.stringify(body) : null,
        });
    } catch {
        // `fetch` lanza igual si se cayo la red que si lo abortamos nosotros;
        // quien los distingue es la señal. Se separan porque el mensaje de un
        // plazo agotado ES distinto —el servidor esta ahi y no contesta— y
        // porque un `plazo_agotado` en el registro es un sintoma de red lenta,
        // no de red caida. Y un aborto tampoco es siempre lo mismo: ver
        // errorDeAbandono().
        throw signal.aborted
            ? finDelIntento(porPlazo)
            : { status: 0, code: 'sin_red', message: 'Sin conexion con el servidor.' };
    }

    // 304: nada cambio desde el ultimo vistazo. No es un error ni trae
    // cuerpo — la pantalla se queda como esta y ahorra el payload entero.
    if (response.status === 304) {
        return { sinCambios: true, etag: response.headers.get('ETag') };
    }

    if (!response.ok) {
        // Aqui SI se tolera un cuerpo ilegible: lo que importa de un error es
        // el codigo de estado, y muchas puertas de enlace devuelven HTML.
        const data = await response.json().catch(() => ({}));

        throw { status: response.status, code: data.code ?? null, message: data.message ?? 'Error de red', data };
    }

    // NO SE TRAGA UN CUERPO ILEGIBLE EN UNA RESPUESTA BUENA. ESTE `throw` ES
    // EL ARREGLO DE LA PANTALLA QUE SE QUEDABA EN BLANCO — NO LO CONVIERTAS
    // EN UN `catch(() => ({}))`.
    //
    // `fetch` resuelve en cuanto llegan las CABECERAS; el cuerpo viaja
    // despues. Si la transferencia se corta a medias —wifi de festival, un
    // proxy, un warning de PHP colado antes del JSON— esto es un 200 de
    // verdad, con su ETag de verdad, y un cuerpo que no se puede leer.
    // Devolviendo `{}` el store guardaba el ETag VIGENTE del servidor
    // emparejado con un tablero VACIO: a partir de ahi cada sondeo recibia un
    // 304 legitimo y la cocina se quedaba a oscuras hasta la siguiente venta,
    // sin un solo error en pantalla. Lanzando, el store no toca ni las
    // comandas ni el ETag, y el siguiente sondeo vuelve a pedir el tablero.
    let data;
    try {
        data = await response.json();
    } catch {
        // Un cuerpo que se corta y un cuerpo que deja de llegar acaban los dos
        // aqui, y los dos son un fallo que no mueve ni el ETag ni las
        // comandas. Se nombran distinto porque son averias distintas: uno es
        // una transferencia rota, el otro un servidor que dejo la respuesta a
        // medias y se quedo callado hasta que se agoto el plazo.
        throw signal.aborted
            ? finDelIntento(porPlazo)
            : { status: 0, code: 'cuerpo_ilegible', message: 'La respuesta llego a medias.' };
    }

    // Un cuerpo `null` o `"algo"` parsea sin fallar y luego se esparce en la
    // nada. Se exige un objeto para que nunca salga de aqui un exito hueco.
    if (data === null || typeof data !== 'object') {
        throw { status: 0, code: 'cuerpo_ilegible', message: 'La respuesta llego a medias.' };
    }

    return { ...data, etag: response.headers.get('ETag') };
}

/**
 * La bateria viaja EN CABECERA y no en la URL, y esa es toda la decision.
 *
 * Metida en la query seria un parametro mas que cambia cada pocos minutos, y
 * con el cambiaria la URL: adios al If-None-Match, adios al 304, y cada
 * tablet se bajaria el tablero entero cada tres segundos toda la noche. En
 * cabecera no toca ni el ETag ni la cache, y el servidor la lee de paso.
 *
 * Nunca lanza NI SE CUELGA: si no hay dato, no hay cabeceras y ya esta. El
 * sondeo del tablero no se rompe por no saber la propia bateria.
 *
 * Lo de no colgarse es la mitad que faltaba, y es del mismo agujero que el
 * plazo de arriba. Esto se resuelve ANTES de llamar a `request()`, o sea fuera
 * de su plazo: una promesa de `navigator.getBattery()` que no resolviera
 * dejaria el sondeo esperando para siempre sin haber llegado a pedir nada, con
 * el candado `sondeando` puesto y la cadena de vueltas muerta — el mismo
 * cuelgue de siempre, entrando por la puerta de al lado. `leerBateria()` no
 * puede lanzar (es la regla dura de bateria.js) pero nadie le prometio a nadie
 * que resolviera, asi que aqui se le pone hora.
 */
async function cabecerasDeBateria() {
    const lectura = await conPlazoDeBateria().catch(() => null);

    if (!lectura) return {};

    const cabeceras = { 'X-Kds-Bateria': String(lectura.nivel) };

    // Se OMITE cuando no se sabe, en vez de mandar un 0: para el servidor un
    // hueco es «no lo se» y un 0 es «desenchufada», y no son lo mismo.
    if (lectura.cargando !== null) {
        cabeceras['X-Kds-Cargando'] = lectura.cargando ? '1' : '0';
    }

    return cabeceras;
}

/**
 * La lectura de bateria contra el reloj: gana la que llegue antes, y si gana
 * el reloj el sondeo sale sin la cabecera y tan tranquilo. Perder el dato de
 * bateria de un ciclo no le cuesta nada a nadie —el panel lo pinta gris y en
 * tres segundos hay otro—; perder el sondeo cuesta la noche entera.
 *
 * El temporizador se cancela pase lo que pase, que si no seria uno nuevo cada
 * tres segundos toda la noche por nada.
 */
function conPlazoDeBateria() {
    let reloj;

    return Promise.race([
        leerBateria(),
        new Promise((resolver) => { reloj = setTimeout(() => resolver(null), PLAZO_BATERIA); }),
    ]).finally(() => clearTimeout(reloj));
}

// Cada llamada trae su plazo del sitio que sabe lo que cuesta perderla. Las
// que lo reciben de fuera (`plazo`) lo cogen del store, que es quien mide el
// enlace; las dos que no, lo tienen escrito aqui y por escrito:
//
// - El ALTA va con el techo. Se hace una sola vez, con alguien delante
//   esperando, y no hay ningun bucle detras que la repita: perderla por
//   impaciencia deja la tablet fuera del servicio hasta que alguien la vuelva
//   a tocar. Ademas es la primera peticion de la sesion, cuando el store
//   todavia no ha medido nada y no tiene con que ser paciente.
// - SALIR va con el suelo. La sesion muere en la tablet pase lo que pase
//   —store.salir() se traga el error a proposito—, asi que esperar aqui solo
//   deja a alguien mirando una pantalla que ya no sirve.
export const api = {
    enrolar: (payload) => request('POST', '/enrolar', payload, { plazo: PLAZO_TOPE }),
    salir: () => request('POST', '/salir', null, { plazo: PLAZO_BASE }),
    comandas: async (etag, plazo, corte) => request('GET', '/comandas', null, {
        headers: {
            ...(etag ? { 'If-None-Match': etag } : {}),
            ...(await cabecerasDeBateria()),
        },
        plazo,
        corte,
    }),
    avanzar: (orderId, area, from, to, plazo) => request(
        'POST',
        `/comandas/${orderId}/${area}/estado`,
        { from, to },
        { plazo },
    ),
    buscar: (q, plazo) => request('GET', `/buscar?q=${encodeURIComponent(q)}`, null, { plazo }),
};
