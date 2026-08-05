# ADR-010 — `event-app`: la puerta pública del asistente, y el manifiesto como cerebro del white-label

**Fecha**: 2026-08-09 · **Estado**: aceptada e implementada (primer slice)

## Contexto

Empieza la app móvil del asistente (doc 11): Flutter, iOS y Android, con la
marca del EVENTO al frente y catorce módulos planeados que cada festival
enciende, apaga y ordena por su cuenta. El primer slice es deliberadamente
pequeño y de **solo lectura**: manifiesto y menús. Ni cuenta, ni pedidos, ni
saldo, ni push.

Cuatro hechos del código, comprobados antes de decidir nada, condicionaron
todo lo demás:

1. **Un evento no tenía forma de nombrarse hacia fuera.** Se identifica por
   `id` autoincremental dentro de su cuenta, y eso no se puede compilar en un
   binario que se publica en una tienda.
2. **`TenantScope` falla CERRADO** — sin contexto emite `where 1 = 0`.
3. **`VendorScope` falla ABIERTO** — sin comercio en contexto no añade
   cláusula, y devuelve el consolidado de la cuenta.
4. **La IP la escribe quien llama** (`trustProxies(at: '*')`, deuda conocida),
   así que cualquier freno por origen es esquivable y, peor, es un cubo
   compartido para los honestos que salen por el mismo NAT.

## Decisión

### Una puerta pública por evento, y el evento se resuelve de la URL

`/api/event-app/eventos/{codigo}/...`, sin token de nada. El asistente no
tiene cuenta todavía, y lo que se sirve es lo mismo que está impreso en el
cartel del puesto: qué se vende y a cuánto. Como no hay nada que escribir,
tampoco hay nada que proteger con una sesión.

Eso obliga a resolver el contexto de otra manera, y aquí está la trampa que
esta puerta existe para no repetir. **Reutilizar `SetTenantContext` daría
`$request->user() === null` → contexto limpio → `TenantScope` emitiendo
`where 1 = 0` → 200 con el manifiesto de fábrica y CERO comercios, sin una
sola excepción ni un log.** Un festival entero de teléfonos enseñando «no hay
dónde comer» mientras el servidor jura que todo va bien. Es la misma trampa
que ya se pagó en el KDS (ADR-009), y aquí la factura sería mayor porque al
otro lado no hay veinte tabletas: hay miles de teléfonos.

La cuenta sale del EVENTO, y lo resuelve `ContextResolver::forEvent()`, en la
misma clase que `forUser()` y `forDevice()` — la regla de «qué cuenta opera»
no puede tener tres sitios donde vivir. Deja el equipo de permisos en nulo,
igual que `forDevice()`: un teléfono anónimo no tiene roles, así que cualquier
`->can()` que se cuele devuelve false por construcción.

**No fija comercio**, y es deliberado: el manifiesto y la lista de puestos son
del evento entero, así que la vista consolidada de la cuenta —lo que da
`VendorScope` sin comercio— es exactamente la correcta. El comercio lo fija la
carta, que es el único endpoint que habla de uno solo.

### El backstop de `VendorScope`, y el filtro que de verdad cierra el agujero

La carta fija comercio **por URL**, que es justo el caso que el fail-open no
cubre. Se replica el `abort_unless(VendorContext::check())` del KDS, dos veces
—en la puerta y en el controlador— a propósito: lo que separa la carta de dos
comercios del mismo festival no puede depender de una sola línea que alguien
pueda borrar sin que ningún test rojo se entere.

Pero el `abort_unless` no es lo que cierra el agujero real. **Lo que lo cierra
es el filtro por PARTICIPACIÓN.** Un organizador con dos festivales tiene
todos sus comercios en el mismo tenant, así que `TenantScope` no separa nada:
sin comprobar `event_vendor`, la app de un evento leería la carta de un
comercio del OTRO cambiando un número en la URL —mismo tenant, consulta
perfectamente válida, 200 con todo dentro—. Se contesta 404 y no 403, como en
el KDS: lo que no es de este evento no existe, y probar ids a mano tampoco
sirve para averiguar qué comercios tiene el organizador en sus otros
festivales.

Hay tests negativos para los tres cruces: otro evento de la misma cuenta, otra
cuenta, y comercio suspendido.

### El código público del evento: `events.public_code`

Ocho caracteres del mismo alfabeto dictable del código del KDS (sin O/0, sin
I/1/l), único **GLOBAL** y emitido por `IssueEventPublicCode` desde
`CreateEvent`, igual que un comercio nace con el de su tablet.

El único global es la segunda excepción de la plataforma a componer los
únicos con `tenant_id`, y por el mismo motivo que la primera: un teléfono
recién descargado pregunta por el código **sin saber a qué cuenta pertenece el
festival**, así que resolverlo es forzosamente cross-tenant y un único por
cuenta permitiría que dos organizadores repartieran el mismo. La excepción
está argumentada por su nombre en `SchemaConventionTest`, y no relaja nada:
un único global es estrictamente más restrictivo que el compuesto.

Emitirlo es **idempotente**, y esa es la diferencia importante con el código
del KDS. El del KDS es un papel pegado a la nevera y se puede reimprimir; este
viaja **compilado dentro del binario** que ya está publicado en la tienda, así
que cambiarlo deja sin servidor a todas las apps instaladas. Por eso
reemplazarlo exige pedirlo explícitamente (`--codigo`), y por eso existe
además el código de vanidad: el día que marketing quiere que el cartel diga
`BOCAO26` en vez de las ocho letras del generador, se fija a mano —lleva
letras que el alfabeto dictable excluye a propósito, así que el sistema nunca
lo inventaría solo—.

La migración rellena los eventos que ya existían y `event-app:codigos` cubre
lo que se salte la acción (un seeder, un import).

### El manifiesto: tabla propia, con valores por defecto que SIEMPRE sirven

`event_app_manifests`, una fila por evento. **Un evento sin manifiesto
configurado devuelve un manifiesto VÁLIDO, nunca un 404.** Es la decisión más
importante de esta tabla: el 404 de esta puerta significa «este código no es
de nadie», y si significara también «nadie ha entrado todavía a elegir un
color», la app de un evento recién creado no arrancaría.

**Columnas tipadas para la marca, JSON para los módulos y los textos.** No es
comodidad, son dos formas distintas de dato:

- La marca es un juego **cerrado y conocido** —cinco colores, dos fuentes, un
  logo, un nombre—, cada uno con su regla («esto es un hexadecimal de seis
  dígitos») y con un formulario del panel que lo validará campo a campo. En
  columnas, la base ayuda y una consulta puede buscar por él.
- La lista de módulos es lo contrario: un catálogo **ordenado y en
  crecimiento** —catorce planeados, uno construido— donde lo que cambia no es
  el valor sino la propia lista. Una columna por módulo significaría una
  migración por módulo, que es exactamente lo que la promesa del white-label
  dice que no puede pasar.

**Todas las columnas de marca son nulas y sin DEFAULT en la base.** El valor
por defecto vive en el modelo y en un solo sitio: dos sitios con el mismo
valor se separan el día que alguien cambia uno, y entonces un evento
configurado y otro sin configurar dejan de parecerse sin que nadie lo haya
decidido. Nulo en la columna significa «no lo ha tocado nadie», que es
información distinta de «lo puso igual al defecto».

Y se **sanea al publicar**, no solo al guardar: un color que no es un color
sale como el de fábrica, y un módulo sin clave se cae en silencio. La app
promete no reventar por un color, pero eso es su red, no una excusa para
mandarle basura por un import o un SQL a mano.

### El ETag se calcula sobre el cuerpo SIN `server_time`

La regla vive en **un solo sitio** —`EventAppController::responder()`— porque
tres copias son tres sitios donde alguien puede meter la hora dentro del hash.
Si entrara, el ETag cambiaría cada segundo, el 304 no ocurriría jamás y cada
arranque de la app se bajaría el manifiesto y la carta entera. Ya mordió en el
KDS; aquí se paga en datos móviles saturados con seis mil personas encima.

`Cache-Control: no-cache, private`, del contrato. `no-cache` no significa «no
lo guardes» sino «pregunta siempre antes de servirlo», que es lo que hace
falta: lo que ahorra el payload es el 304, no la caché del cliente. Es una
cabecera sobre la caché de **quien pregunta** y no dice nada de la del
servidor, que es otra cosa y está abajo.

### El precio que se publica es el que va a cobrar la caja

`precio_cents`, entero en centavos, con la modalidad de ITBIS del comercio ya
aplicada. **La regla no se reimplementa**: `ResolveItbisMode` dice la
modalidad y el propio `ItbisMode` la aplica, las mismas dos piezas que usa
`PlaceOrder`, para que el día que cambie el 18 % cambie en un sitio. Un
comercio que vende con el impuesto por fuera publicando su precio base sería
un menú que miente por un 18 % delante de una cola, y el asistente lo
descubriría en la caja.

**Solo productos activos.** Un producto desactivado desaparece; no se marca
«agotado», que es inventario y es de otra fase. Es lo contrario de lo que hace
el POS, que los manda todos y los pinta en gris: al cajero le sirve saber que
el plato existe y hoy no se sirve, y al asistente solo le sirve para pedir lo
que no hay. Y la categoría que se queda sin nada publicable tampoco viaja: un
apartado vacío en la carta se lee como un fallo.

### Las fotos salen absolutas

`UrlAlcanzable` deja absoluta cualquier URL que venga relativa. Los paneles y
el POS piden sus imágenes desde una página que el propio servidor sirvió, así
que `/storage/...` se completa sola; la app recibe JSON y se la da a un widget
de imagen que no tiene ninguna página de la que colgar. Una ruta relativa ahí
no es una foto rota bonita: es la pantalla de menús del festival entera sin
fotos, y solo se descubre en un teléfono.

### El vocabulario publicado va en español, y vive en una clase

El contrato publica `"estado": "activo"` y `"tipo": "cocina"`, no los `value`
de los enums, que están en inglés. La app compara contra esas cadenas, así que
son parte del contrato tanto como los nombres de los campos, y cambiarlas
rompe teléfonos ya publicados sin que ningún test de aquí se ponga rojo. Por
eso viven en `VocabularioPublico` y no repartidas: una superficie se mira
entera antes de tocarla. Tampoco se usan los `getLabel()` de los enums, que
son texto de pantalla del panel del organizador y cambian cuando a alguien no
le gusta cómo suena.

### Ningún freno por IP en esta puerta, y el porqué del número que no hay

Esta puerta nació con uno: `throttle:event-app`, 600 por minuto por (evento,
IP), con su 429 en español. **Se ha quitado**, y merece contarse entero porque
el razonamiento vale para cualquier freno que se escriba mientras
`trustProxies(at: '*')` siga abierto.

El número no era el problema. La llave sí, y no hay número que la arregle:

- **Contra quien ataca no cuenta.** La IP la escribe quien llama, así que
  estrena IP —y con ella cubo— en cada petición. Jamás llega al techo, sea 600
  o sean 60.
- **Contra el público sí cuenta.** Los teléfonos de un festival salen por el
  NAT de dos o tres operadores, así que miles de asistentes honestos comparten
  UN cubo. Doc 11 §6 habla de +6.000 personas sobre datos móviles; a dos
  peticiones por arranque y una más por cada carta que se mira, la cola del
  sábado a las nueve llena 600 en un minuto sin que nadie haga nada raro. Y el
  304 tampoco ayudaba: el freno va delante del middleware, así que también
  paga.
- **Y quien ataca elige QUÉ cubo llena.** Bastan 600 peticiones con la IP del
  operador escrita en `X-Forwarded-For` para dejar sin app, evento a evento, a
  todo el que salga por ella. Eso ya no es un freno inútil: es el botón de
  apagado con otro nombre del que habla CLAUDE.md —un contador que sube quien
  ataca, sobre algo que él elige—, y encima apuntando al público.

Un freno que no puede acertar y sí puede negar un acierto vale menos que
ninguno, así que **el número elegido es cero, y esa es la parte documentada**:
no hay techo por IP en `/api/event-app` porque hoy la IP no discrimina nada.
Los dos tests de `EventAppThrottleTest` fijan las dos mitades —setecientas
peticiones del mismo origen se sirven, y una IP ajena no se puede apagar con
una cabecera— y los dos se ponían rojos con el limitador puesto.

Lo que hace baratos estos endpoints nunca fue el freno: son de **solo lectura**
y detrás no hay nada que escribir que un exceso pueda corromper.

El techo de volumen que sí hace falta va en el **borde** —ngrok hoy, el
balanceador mañana—, que es el único sitio donde la IP todavía es cierta. El
día que `at:` se acote a los rangos del borde, `$request->ip()` vuelve a
discriminar y este freno se puede reescribir aquí con un número defendible;
hasta entonces, ninguno lo es.

### El ETag ahorra red, no servidor: por eso hay caché de respuesta

Quitado el freno, lo único que quedaba sosteniendo la puerta era el ETag, y el
ETag **no ahorra ni una consulta**. Un 304 ejecuta exactamente las mismas tres
—o cinco, o ocho— consultas que un 200; lo único que se ahorra son los bytes
del cuerpo. Es un ahorro real en la red saturada de un recinto y ninguno en el
servidor, que es lo que se cae.

Los tres endpoints son de solo lectura y **su respuesta es idéntica para todos
los que preguntan por el mismo evento**: no hay usuario, no hay token, no hay
nada personalizado. Calcularla seis mil veces es calcular seis mil veces lo
mismo. Así que se cachea el **cuerpo** por `(evento, endpoint, comercio)` con
una ventana de **10 segundos**, y el ETag se calcula sobre el cuerpo ya
cacheado: el primer teléfono paga las consultas, los siguientes no, y un 304
tampoco toca el catálogo.

Medido, para una segunda petición idéntica (store `array`):

| Endpoint | Antes | Después |
|---|---|---|
| `manifiesto` | 3 consultas | 2 |
| `comercios` | 5 | 2 |
| `menu` | 8 | 4 |

**Diez segundos, y el número sale de la forma de la curva.** Con un TTL de `t`
segundos se ahorra todo menos `60/t` cálculos por minuto, sea cual sea el
volumen: a mil peticiones por minuto, 5 s ahorra el 98,8 %, 10 s el 99,4 %,
30 s el 99,8 % y 60 s el 99,9 %. Todo el ahorro está en los primeros segundos y
de ahí en adelante la curva es plana, mientras que lo que cuesta estirarla se
paga entero en frescura. Un comercio que se queda sin un plato lo desactiva y
quiere que se note; diez segundos es menos de lo que tarda alguien en llegar
del teléfono al puesto.

**Lo que NO se cachea es la mitad importante.** La puerta —resolver el evento
del código, la cuenta, el comercio y su participación— se vuelve a ejecutar en
cada petición. Aquí no hay token que revocar, así que esa revalidación es la
única revocación que existe: **una revocación cacheada es una revocación que no
ocurre.** Un comercio suspendido a media tarde recibe 404 en su carta en la
petición siguiente, no cuando caduque un TTL. Lo que sí puede ir hasta diez
segundos por detrás es su **nombre en la lista**, y eso es un nombre, no un
acceso.

**Qué invalida.** Cambiar el manifiesto sí: lo tira el propio modelo al
guardarse, porque es la única de las tres respuestas que alguien cambia
*mirando* el resultado —se elige un color en el panel y se mira el teléfono, y
si no cambia lo que se concluye no es «hay una caché» sino «el panel no
guardó»—. Rotar un PIN no, porque no aparece en ninguno de los tres cuerpos; ni
una venta, ni una comanda. El catálogo tampoco se engancha: son escrituras del
camino caliente del POS y del panel del comercio, y colgarles un borrado por
cada evento en el que participa ese comercio metería una consulta de la app del
asistente dentro de una venta. Para eso el TTL es corto.

**Y en la caché solo viajan datos planos.** `config/cache.php` fija
`serializable_classes => false`, así que cualquier objeto guardado vuelve
convertido en `__PHP_Incomplete_Class`. Se descubrió midiendo con el store
`database`: el `(object)` de `textos` volvía como
`{"__PHP_Incomplete_Class_Name":"stdClass"}` —basura servida a la app en el
campo que el contrato promete como diccionario, y un ETag distinto en cada
petición, o sea el 304 muerto— y no se veía en los tests, que corren con el
store `array`, donde nada se serializa. Por eso el reparto:
`EventAppController::responder()` guarda **qué** se responde y
`publicar()` decide **cómo** se escribe el JSON, ya fuera de la caché.
`EventAppCacheTest` fija las dos cosas, y una de sus pruebas corre a propósito
contra el store `database`.

## Lo que se descartó

- **Meter el manifiesto en `events` como columnas o un JSON.** Habría metido
  quince columnas de presentación en la tabla que sostiene ventas,
  liquidaciones y comisiones, y habría hecho que el ETag del manifiesto
  cambiara cada vez que alguien corrige la hora de cierre del festival.
- **Un `slug` derivado del nombre en vez de un código.** El nombre cambia
  («Bocao 2026» → «Bocao Food Fest 2026») y con él se iría el slug, dejando
  sin servidor a los binarios ya publicados. El código es opaco justo para que
  nada del negocio pueda arrastrarlo.
- **Tokens de asistente en este slice.** Habría abierto la superficie de
  escritura entera —cuenta, OTP, revocación— por una puerta que hoy solo
  necesita leer. El doc 11 la describe; se construye cuando haya algo que
  escribir.
- **`Cache-Control: public`.** El cuerpo es idéntico para todo el mundo, así
  que una caché compartida delante ahorraría miles de peticiones y sería
  defendible. Se deja para cuando el borde esté acotado: hoy sería regalar el
  control de qué se sirve a una infraestructura que todavía no existe.
- **Un cursor incremental o websockets.** Mismo argumento que el KDS: el
  snapshot con ETag es idempotente, se autorrepara y no tiene carreras.
- **Publicar los `value` de los enums en inglés.** Más barato de mantener aquí
  y más caro allí: obligaría a la app a llevar su propia tabla de traducción y
  a mantenerla sincronizada con este repo.
- **404 cuando el evento no tiene manifiesto.** Ver arriba: es una app que no
  arranca porque nadie eligió un color.

## Consecuencias

- **Un evento cerrado o liquidado SIGUE respondiendo.** Solo el borrador se
  esconde. El festival termina un domingo a las dos de la mañana y seis mil
  teléfonos siguen teniendo la app instalada: apagar la puerta al cerrar
  convertiría todas esas pantallas en un error. `estado` dice la verdad y la
  app decide qué hacer con ella.
- **El manifiesto todavía no tiene pantalla en `/event-panel`.** Se configura
  por tinker o por seeder. Es aditivo —la tabla y sus defectos ya están— pero
  hasta que exista, el white-label es una promesa que solo un desarrollador
  puede cumplir.
- **`productos.descripcion` viaja siempre en nulo**: la columna no existe en
  el catálogo. Está en la respuesta porque el contrato la promete, y añadirla
  después no puede obligar a publicar una versión nueva de la app.
- **`disponible` es constante `true` hoy.** Se manda igual para que el día que
  el inventario decida esto cambie el valor y no el contrato.
- **La segunda excepción al único compuesto.** La lista de
  `SchemaConventionTest` tiene ahora dos entradas; que siga costando
  explicarlas ahí antes que en producción.
- **`trustProxies(at: '*')` sigue abierto**, y esta puerta lo hereda. El doc 11
  lo llama requisito de la app y no opcional. Mientras siga así, esta puerta no
  lleva freno por IP —ver arriba—; lo que la sostiene es que es de solo lectura,
  que no hay nada que escribir detrás y que la respuesta se calcula una vez por
  evento y no una por teléfono.
- **El techo de volumen del borde no existe todavía, y eso es deuda conocida.**
  La caché hace barata cada petición; no pone ningún límite a cuántas caben. Lo
  segundo solo se puede hacer delante del backend, y hoy delante hay un túnel
  ngrok sin nada configurado.
- **`CACHE_STORE` es `database`, y eso limita lo que la caché puede ahorrar.**
  Con ese store la caché es *otra consulta contra la misma base* que se quiere
  descargar: convierte N consultas en 1, lo que gana en `comercios` y en `menu`
  y **no gana nada en `manifiesto`** —una consulta indexada sustituida por otra
  consulta indexada—. Para que descargue la base de verdad hace falta un almacén
  **en memoria** (Redis, o APCu con un solo proceso), y eso es un cambio de
  `.env` en el despliegue, no de código.
- **La lista de comercios puede ir hasta 10 s por detrás de una suspensión.**
  El contrato dice «un comercio suspendido desaparece en la siguiente
  petición»; con la caché es «en la siguiente petición, a lo sumo diez segundos
  después». Su **carta** sí se apaga en el acto, porque la puerta no se cachea.
  Es un matiz del contrato que el otro lado tiene que conocer.
- **La app no recibe 429 de esta puerta**, y el contrato lo dice. Si algún día
  vuelve a haber un techo, vuelve también esa línea del contrato antes que el
  código.
- **«Publicado» significa «no es un borrador»**, y ahora el contrato lo dice
  con esas palabras. Era la única lectura que dejaba viva la app instalada el
  lunes, pero estaba solo aquí: el otro lado podía haberla leído al revés y
  esperar un 404 al terminar el festival.
- **Lo que puede viajar nulo está anotado en el contrato**, campo a campo:
  `evento.lugar`, `marca.logo_url` y las dos fuentes. Es el estado de fábrica
  de todo evento recién creado —o sea, hoy el de todos—, y el ejemplo del
  contrato los enseñaba con valor.
- **Un manifiesto corrupto degrada, no revienta.** `modulos` o `textos` con una
  forma que no es la suya —un escalar metido por un import o por SQL a mano—
  se sirven como los de fábrica. Era un 500 en el único endpoint sin el cual la
  app no arranca.
