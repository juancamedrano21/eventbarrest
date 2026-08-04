import { createApp } from 'vue';
import { createPinia } from 'pinia';
// El mismo sistema visual del POS: variables, overlays, botones y campos.
import '../../css/device-theme.css';
import App from './App.vue';
import { usePantalla } from './store';
import { hasToken } from './api';

const app = createApp(App);
app.use(createPinia());
app.mount('#kds');

const pantalla = usePantalla();

// El reloj de la pantalla. Uno solo para toda la app: veinte tarjetas con su
// propio setInterval serian veinte temporizadores por nada.
setInterval(() => { pantalla.ahora = Date.now(); }, 1000);

// El sondeo se reprograma DESPUES de cada respuesta, no en un intervalo fijo:
// asi una peticion lenta no se solapa con la siguiente, y el retroceso ante
// error se aplica solo. El jitter evita que veinte tablets golpeen a la vez.
let temporizador = null;

// Un despertar que llego con el candado puesto y todavia no se ha atendido.
// Se declara AQUI ARRIBA y no junto a despertar(): la primera `vuelta()` se
// llama unas lineas mas abajo y, sin token, ni siquiera espera a nada — su
// `finally` correria dentro de la evaluacion del modulo y leer esta variable
// desde su zona muerta seria un ReferenceError con la pantalla de alta puesta.
let despertarPendiente = false;

function programar(ms) {
    clearTimeout(temporizador);
    temporizador = setTimeout(vuelta, ms + Math.random() * 500);
}

// LA CADENA NO SE ROMPE PASE LO QUE PASE: EL `programar` VA EN EL `finally`.
//
// Escrito seguido —el await y debajo la reprogramacion— cualquier cosa que
// saliera mal del sondeo se llevaba por delante el bucle ENTERO, no una
// vuelta: sin `programar()` no hay siguiente `setTimeout`, y una tablet
// colgada de la pared se queda pintando el tablero de hace un rato con cara
// de estar viva. `refrescar()` de hoy se traga sus errores, pero eso es una
// propiedad de la otra punta que aqui no se puede dar por supuesta.
//
// Esto NO sustituye al plazo del `fetch` (api.js), lo complementa: el
// `finally` cubre lo que LANZA y el plazo cubre lo que NO VUELVE. Un await
// colgado no llega ni al finally, y sin plazo el bucle seguiria muriendo aqui.
async function vuelta() {
    try {
        if (hasToken()) await pantalla.refrescar();
    } finally {
        // Un despertar que llego mientras esto viajaba se atiende AHORA y no
        // dentro de medio minuto: ver despertar().
        if (despertarPendiente) {
            despertarPendiente = false;
            programar(0);
        } else {
            programar(pantalla.proximaEspera());
        }
    }
}

// El bucle arranca SIEMPRE, con token o sin el, y es la propia vuelta la que
// decide si hay algo que pedir.
//
// Arrancarlo solo cuando ya habia token —que es lo que hacia antes— dejaba
// la tablet recien dada de alta con el tablero congelado para siempre: el
// alta cargaba el tablero una vez y nadie volvia a preguntar hasta la
// siguiente recarga de la pagina. En una tablet colgada de una pared eso es
// una pantalla que parece viva y no lo esta, que es el peor fallo posible
// aqui. Lo unico que lo delataba era el reloj de frescura subiendo.
vuelta();

// Volver a mirar la pantalla, o recuperar la senal, pregunta YA: esperar tres
// segundos con la tablet en la mano ya se nota.
//
// Pero no si ya hay un sondeo viajando. Estos dos eventos llegan cuando les
// da la gana —el `online` de Android salta varias veces al reengancharse al
// wifi— y sin este freno lanzaban un sondeo encima del que estaba en curso:
// dos respuestas pedidas con ETags distintos, y la que llegara tarde
// repintaba el tablero de hace un ciclo. El store tambien lo frena por su
// lado (usePantalla.sondeando); aqui se evita ademas reiniciar el
// temporizador para nada.
//
// LO QUE NO SE PUEDE ES TIRAR LA SEÑAL. Frenar el despertar no cuesta lo que
// dura el sondeo en curso: cuesta eso MAS el retroceso entero que viene
// detras, que con la red caida un rato ya va por 30 s. Medido en el caso que
// importa —diez minutos de red muerta y el wifi volviendo justo mientras hay
// una peticion en el aire— eran 39 s de tablero viejo con la red ya buena, y
// el rescate no lo hacia el despertar sino el temporizador del retroceso, que
// es exactamente lo contrario de lo que este evento existe para hacer. Se
// apunta y se atiende al soltarse el candado, en el `finally` de vuelta().
//
// Ese `programar(0)` es ademas lo que reinicia el retroceso: no consulta
// `proximaEspera()`, asi que la escalera de 3 → 30 s no se aplica a la primera
// pregunta despues de volver la red. Si esa tambien falla, la escalera sigue
// donde estaba, que es lo correcto — el `online` de Android promete poco.
function despertar() {
    if (pantalla.sondeando) {
        despertarPendiente = true;

        return;
    }

    programar(0);
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') despertar();
});

// LOS DOS EVENTOS DE RED VAN AL STORE ENTEROS, Y NO SE DECIDE NADA AQUI.
//
// Este evento, ademas de despertar, puede cortar el sondeo que estuviera
// viajando: si la conexion cambio, esa peticion salio por una que ya no existe.
// Pero «puede» es la palabra: el `online` de Android salta varias veces por
// gusto, tambien con todo funcionando, y cortar por cada uno apagaba el tablero
// de un enlace lento pero SANO. Quien sabe si hubo cambio de verdad es el store
// —el es quien recuerda si nos constaba que no habia red y con que conexion
// salio el sondeo en curso—, asi que la decision vive alli entera (redVolvio()
// y abandonarSondeo()) y aqui solo se le cuenta lo que ha pasado.
//
// El `visibilitychange` de arriba NO corta nada: alguien que mira la pantalla
// no cambia el estado de la red, y cortar por cada vistazo dejaria sin acabar
// las respuestas de un enlace lento justo cuando mas se le mira.
window.addEventListener('online', () => {
    pantalla.redVolvio();
    despertar();
});

// Que la red se FUE si es un hecho, y hay que apuntarlo aunque no se haga nada
// mas con el: es lo que convierte al proximo `online` en un cambio de conexion
// y no en otro salto por gusto.
window.addEventListener('offline', () => {
    pantalla.redSeFue();
});

// Que la pantalla no se apague en mitad del servicio. Se vuelve a pedir al
// volver de segundo plano: el navegador suelta el bloqueo al ocultarse.
async function mantenerEncendida() {
    try {
        await navigator.wakeLock?.request('screen');
    } catch { /* sin permiso o sin soporte: no es critico */ }
}

mantenerEncendida();
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') mantenerEncendida();
});
