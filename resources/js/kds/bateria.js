// Lo que este aparato sabe de SI MISMO, que son dos cosas y salen las dos del
// mismo sitio: el puente del APK (window.PayroneKds). Cuanta bateria le queda,
// que viaja en cada sondeo, y quien es, que viaja UNA sola vez, en el alta.
// Viven juntas porque el puente es uno y las reglas de desconfianza son las
// mismas; lo que cambia es cuando se manda cada una y para que sirve.
//
// ---------------------------------------------------------------------------
// LA BATERIA
// ---------------------------------------------------------------------------
//
// Cuanta bateria le queda a esta tablet. Es el unico dato de la pantalla que
// no sale del servidor: una tablet colgada de una pared se queda sin bateria
// sin avisar, y cuando se apaga nadie lo nota porque una pantalla apagada se
// parece muchisimo a una noche floja. Esto es lo que deja que el panel lo vea
// venir.
//
// DOS FUENTES, EN ESTE ORDEN:
//
// 1. window.PayroneKds.bateria() — el puente del APK, que es la fuente buena.
//    Devuelve un JSON en una cadena, '{"nivel":87,"cargando":true}', o la
//    cadena vacia cuando ni el sistema lo sabe. El puente NO sabe hacer nada
//    mas: no escribe, no lee comandas, solo cuenta un hecho fisico del
//    aparato.
// 2. navigator.getBattery() — el respaldo para cuando la pantalla se abre en
//    un navegador normal. Chromium todavia lo trae; Safari y Firefox lo
//    quitaron, asi que en esos dos no habra nada y esta bien que asi sea.
//
// Y si no hay ninguna: null. NO se inventa un cero. Un hueco es un dato
// honesto —el panel pinta «sin dato» en gris— mientras que un cero inventado
// haria que el panel avisara de una bateria agotada que no existe, y a los
// tres dias nadie miraria ya ese aviso.
//
// NADA DE AQUI PUEDE LANZAR. Es la regla dura de este archivo: getBattery()
// devuelve una promesa y en algunos navegadores revienta —contexto inseguro,
// permiso denegado—, y el puente del APK puede no existir o devolver
// cualquier cosa. Una pantalla de cocina no se queda sin comandas porque no
// supo leerse la propia bateria.

/**
 * @returns {Promise<{nivel: number, cargando: boolean|null}|null>}
 */
export async function leerBateria() {
    const delApk = desdeElApk();

    if (delApk !== null) return delApk;

    return desdeElNavegador();
}

/** El puente del APK. Sincrono y en una cadena JSON. */
function desdeElApk() {
    try {
        const crudo = window.PayroneKds?.bateria?.();

        // La cadena vacia es la respuesta legitima del APK para «ni yo lo
        // se»: no es un fallo, y por eso no se cae al respaldo con otra cara.
        if (typeof crudo !== 'string' || crudo === '') return null;

        return normalizar(JSON.parse(crudo));
    } catch {
        // JSON roto, puente a medio inyectar, WebView antiguo: da igual cual
        // de los tres. Sin dato y a seguir.
        return null;
    }
}

/** El respaldo del navegador. `level` viene en 0..1, no en porcentaje. */
async function desdeElNavegador() {
    try {
        const bateria = await navigator.getBattery?.();

        if (!bateria) return null;

        return normalizar({
            nivel: bateria.level * 100,
            cargando: bateria.charging,
        });
    } catch {
        return null;
    }
}

/**
 * Lo que sale de aqui es lo unico que el resto de la app llega a ver, asi que
 * la desconfianza se concentra en esta funcion: las dos fuentes son ajenas
 * —una es un APK que se actualiza por su cuenta— y ninguna promete nada.
 *
 * Un nivel que no sea un numero de 0 a 100 se descarta ENTERO en vez de
 * recortarse a los bordes: un 150 no es un 100, es una fuente que no sabe lo
 * que dice, y creerle el resto seria pintar un dato inventado con cara de
 * medido. `cargando` puede quedarse en null; lo que no puede es volverse
 * false por no saberse, porque eso pintaria desenchufado un cargador puesto.
 */
function normalizar(dato) {
    const nivel = Math.round(Number(dato?.nivel));

    if (!Number.isFinite(nivel) || nivel < 0 || nivel > 100) return null;

    return {
        nivel,
        cargando: typeof dato?.cargando === 'boolean' ? dato.cargando : null,
    };
}

// ---------------------------------------------------------------------------
// LA IDENTIDAD
// ---------------------------------------------------------------------------
//
// Quien es este aparato. Sirve para UNA cosa: que el alta reconozca a la
// tablet que ya estuvo colgada en este puesto y le devuelva SU fila en vez de
// fabricar otra. Hoy, en la base de pruebas, hay seis filas «Cocina 1» que son
// la misma Galaxy Tab, cinco de ellas fantasmas con una bateria congelada de
// hace horas.
//
// NO ES UNA CREDENCIAL. Se manda junto al codigo del comercio y al PIN del
// puesto, y no descuenta ni un caracter de ninguno de los dos. Esta cadena no
// es secreta —el aparato se la da a quien la pida— asi que el dia que sirviera
// para entrar sin PIN, entrar en un puesto ajeno costaria averiguar dieciseis
// caracteres.
//
// Y por eso viaja SOLO en el alta. En el sondeo no pinta nada: ese ya va
// firmado con el token, y repetir un identificador en cada peticion de cada
// tablet toda la noche solo aumenta la superficie sin comprar nada.
//
// De donde sale, del lado del APK: Settings.Secure.ANDROID_ID. No el numero de
// serie —Build.getSerial() exige un permiso privilegiado desde Android 10 y
// devolveria «unknown» en toda la flota—, sino el identificador que desde
// Android 8 es estable por aparato Y por firma de la app: sobrevive a
// reinstalar el APK y a borrar los datos, y no deja que otra aplicacion
// reconozca a esta misma tablet.
//
// Cuando no hay puente —la pantalla abierta en un navegador normal— devuelve
// null y el alta sigue su camino de siempre, con una fila nueva. Un hueco es
// honesto: inventar aqui una huella del navegador seria peor, porque cambia
// con cada actualizacion de Chrome y juntaria en una sola fila dos tabletas
// distintas del mismo modelo.

/** Lo que cabe en la columna del servidor; mas largo que esto no es una identidad. */
const LARGO_MAXIMO = 64;

/**
 * El mismo alfabeto que valida el servidor. Se comprueba TAMBIEN aqui a
 * proposito: si una cadena rara llegase al alta, el servidor rechazaria la
 * peticion ENTERA y la tablet se quedaria sin poder enrolarse por culpa de un
 * dato que era opcional. Descartarla en origen deja el alta funcionando —sin
 * identidad, que es un caso previsto— en vez de romperla.
 */
const ALFABETO = /^[A-Za-z0-9_.:-]+$/;

/**
 * @returns {string|null}
 */
export function leerIdentidad() {
    try {
        const crudo = window.PayroneKds?.identidad?.();

        // La cadena vacia es la respuesta legitima del puente para «el sistema
        // no me la da»: no es un fallo y no hay nada que mandar.
        if (typeof crudo !== 'string') return null;

        const limpia = crudo.trim();

        if (limpia === '' || limpia.length > LARGO_MAXIMO) return null;
        if (!ALFABETO.test(limpia)) return null;

        // Los dos comodines que NO identifican a nadie. Un ANDROID_ID a ceros
        // aparece en emuladores y en clones baratos, y «unknown» es lo que
        // devuelve Android cuando no quiere contestar: mandarlos fundiria en
        // una sola fila todas las tabletas que los repitan, que es el mismo
        // destrozo de hoy pero al reves y sin arreglo posible.
        if (/^0+$/.test(limpia) || limpia.toLowerCase() === 'unknown') return null;

        return limpia;
    } catch {
        // Puente a medio inyectar, WebView antiguo, un APK que cambio de
        // opinion: sin identidad y a seguir. El alta no se cae por esto.
        return null;
    }
}
