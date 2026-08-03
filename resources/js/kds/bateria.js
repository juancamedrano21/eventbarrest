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
