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
falta: lo que ahorra el payload es el 304, no la caché.

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

### Un freno propio, generoso y honesto sobre lo que hace

`throttle:event-app`, 600 por minuto, con la llave compuesta de evento y
origen, y un 429 con `code` y mensaje en español —el «Too Many Attempts.» de
Laravel no le dice nada a un asistente—.

**El número es grande a propósito, y eso no es dejadez.** Aquí quien llama no
es una caja ni una tablet: son los teléfonos del público, miles, saliendo
todos por el NAT de dos o tres operadores. Con la IP colapsada así, un techo
estrecho no frena a quien ataca —la IP la escribe quien llama— y sí deja fuera
al asistente que abrió la app en el peor momento del sábado. Un freno nunca
puede negar un acierto.

Lo que hace baratos estos endpoints no es el freno: es que son de solo lectura
y llevan ETag, así que la app repite y recibe 304s. El limitador es un techo
de volumen contra un cliente roto en bucle, y decirlo así evita que alguien lo
baje creyendo que protege algo. El evento entra en la llave para que el bucle
de un festival no consuma el cubo de otro que comparte operador.

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
  lo llama requisito de la app y no opcional. Mientras siga así, el freno de
  esta puerta es un techo de volumen y nada más; lo que la sostiene es que no
  hay nada que escribir detrás.
