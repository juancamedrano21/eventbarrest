# ADR-009 — El KDS: la comanda como hecho nuevo, y la tablet como dispositivo

**Fecha**: 2026-08-03 · **Estado**: aceptada e implementada

## Contexto

Un puesto de festival cobra por el POS y prepara por detrás. Hasta hoy el
puente entre las dos cosas era papel: el cajero imprimía una comanda y
alguien la clavaba en una barra. Eso funciona hasta que hay cola, y entonces
nadie sabe qué falta, quién lleva más esperando ni qué se quedó a medias.

La petición era una pantalla de cocina para una tablet, con tres estados
—PENDIENTE, EN PROCESO, LISTA—, acceso por un código de comercio y un PIN de
puesto, y las órdenes apareciendo solas.

Cuatro hechos del código, comprobados antes de decidir nada, condicionaron
todo lo demás:

1. **Una orden cobrada es historia inmutable.** `Order::updating` lanza
   mirando el estado ORIGINAL sin importar qué columna cambie,
   `Order::assertRowIsWritable()` reconsulta la fila real para que ni un
   update acotado por clave lo esquive, y `OrderLine::updating` lanza
   SIEMPRE. Y como `PosOrderController` envuelve `PlaceOrder` + `PayOrder` en
   una sola transacción, todo lo que ve la cocina nace `Paid`: nace cerrado a
   escritura.
2. **El área de despacho no estaba congelada.** Vivía en
   `categories.dispatch`, que es mutable: recategorizar un producto en enero
   habría reescrito qué comandas fueron de cocina en diciembre.
3. **El sistema no sabía a qué hora se cobró.** `paid_at` lo pone el
   servidor, y el POS es offline-first.
4. **No hay broadcasting montado.** Ni `config/broadcasting.php`, ni
   `routes/channels.php`, ni reverb/pusher en `composer.json`, ni un solo
   `Event::fake()` en toda la suite.

## Decisión

### La comanda es un hecho nuevo, en tabla propia y MUTABLE

`kitchen_tickets` vive al lado de un dominio deliberadamente inmutable y es
deliberadamente lo contrario, igual que `Refund`: un hecho nuevo que
referencia la venta, jamás una edición de la venta.

**PENDIENTE es la AUSENCIA de fila.** El tablero es `orders LEFT JOIN
kitchen_tickets`, con las áreas derivadas de `order_lines.dispatch`. De ahí
salen tres cosas gratis: no hay observer, no hay job, no hay backfill y no
hace falta un comando reconciliador. Una venta sincronizada aparece como
pendiente por el mero hecho de existir, y ninguna puede perderse por el
camino.

**La clave es el par (orden, área), no la orden.** Una venta con dos cervezas
y un taco genera DOS comandas que avanzan por separado: la barra sirve en
veinte segundos y la cocina tarda ocho minutos, y con un solo ticket quien
marcase «lista» estaría mintiendo por la otra mitad. Cada tarjeta lleva el
estado de su hermana para que nadie entregue media orden.
(`operating_units.kind` trae `mixed` por defecto: el puesto mixto es lo que
sale de fábrica, no la excepción.)

### Tres estados y solo tres

`Pendiente / EnProceso / Lista`, por decisión del dueño del producto.
`Lista` es TERMINAL, y para que esa columna no crezca toda la noche las
comandas listas de hace más de veinte minutos caen del tablero. Las
pendientes y en proceso NUNCA caen: un pedido olvidado tiene que seguir
gritando.

Se puede volver atrás (`Lista → EnProceso`, `EnProceso → Pendiente`) porque
los dedazos existen, y al volver se BORRA el sello de hora que se deshace: si
no, un informe de tiempos mediría un instante que ya nadie sostiene.

Consecuencia asumida: sin estado `Cancelada`, un plato ya reembolsado no se
cancela solo. La protección es una franja roja en la tarjeta —`RefundOrder`
no cambia `orders.status` y puede ser parcial, así que vigilar el estado de
la orden jamás revelaría la devolución.

### El camino del dinero solo se toca para congelar dos datos

`order_lines.dispatch` (el área, al vender) y `orders.device_sold_at` (la
hora del reloj del cajero). Ninguna de las dos es una escritura posterior:
las dos ocurren en el `creating`, que sigue abierto. `device_sold_at` se
descarta si viene del futuro o de hace más de un día —eso no es retraso de
sincronización, es un reloj mal puesto— y NO se usa jamás para dinero,
numeración ni cortes de día.

También entra `order_lines.notes` («sin cebolla»), que es lo único de esa
tabla que no es un hecho económico. Queda FUERA de la comprobación de
idempotencia a propósito: un borrador guardado antes de que existieran las
notas se reenvía sin ellas, y es la misma venta, no una referencia
reutilizada.

### La tablet se ENROLA; no es una sesión, y no usa Sanctum

Se teclea código de comercio + PIN de puesto UNA vez, en el montaje, y la
tablet queda con su propio token hasta que alguien la revoque desde el panel.
Nadie teclea nada a las dos de la mañana, y una tablet perdida se mata sola
sin rotar el PIN de todo el equipo.

**No se usa Sanctum, y no es purismo — son dos fallos concretos:**

- `config/sanctum.php` deja `'guard' => ['web']`, y el guard recorre esos
  guards ANTES de mirar el Bearer. En una tablet donde alguien dejó
  `/event-panel` abierto, la API del KDS autenticaría a ESA persona, con SU
  cuenta y SU comercio, sin haber tecleado ningún PIN.
- `sanctum:prune-expired` está agendado a diario y su segunda tarea borra por
  `created_at` cuando `sanctum.expiration` está puesta —y lo está—,
  ignorando `expires_at`. Todas las tabletas de más de quince días
  desaparecerían a la vez, en silencio y sin log.

El contexto lo fija `ContextResolver::forDevice()`, en la MISMA clase que
`forUser()` porque su docblock promete que esa regla vive en un solo sitio, y
deja `setPermissionsTeamId(null)` a propósito: un dispositivo no tiene roles,
así que cualquier `->can()` que se cuele devuelve false. Fail-closed por
construcción.

**La trampa cara, escrita para que nadie la repita**: reutilizar
`SetTenantContext` o `EnsurePosCapability` daría `$request->user() === null`
→ contexto limpio → `TenantScope` emitiendo `where 1 = 0` → **200 con el
tablero vacío, cero excepciones, cero logs**.

`kitchen_tickets.vendor_id` y `kds_devices.vendor_id` son NOT NULL, a
diferencia de `orders` y `refunds`. `VendorScope` falla ABIERTO: sin comercio
activo no añade cláusula. La columna es el último backstop de la base contra
que un comercio lea las comandas de su competidor en el mismo festival, y el
middleware repite el `abort_unless(VendorContext::check())` por encima. Es
redundante, y así debe ser.

### Sondeo cada 3 s con ETag, no websockets

El motivo es aritmético. El suelo de latencia del sistema es el outbox del
POS: `runSync()` vuelve sin hacer nada si no hay señal. Optimizar el tramo
servidor→tablet de 3 s a 50 ms mientras el tramo anterior tarda minutos es
optimizar el eslabón equivocado. Lo que sí se hizo fue bajar ese empuje de
15 s a 5 s cuando la bandeja no está vacía, que es la única línea que acorta
la espera real de la cocina.

Se descartó también el cursor incremental (`?desde=`): el keyset
`(updated_at, id)` tiene una carrera que los milisegundos no arreglan —una
transacción que sella `T1` pero confirma después de otra con `T2 > T1` queda
por detrás del cursor para siempre—, y el síntoma sería «a veces un pedido no
sale», intermitente y carísimo de diagnosticar un sábado a las nueve. El
snapshot completo es idempotente y se autorrepara en la siguiente vuelta.

**El detalle que decide si el 304 sirve de algo**: el ETag se calcula sobre el
cuerpo EXCLUYENDO `server_time`. Si esa hora entrara en el hash, el ETag
cambiaría cada segundo y el 304 no ocurriría jamás. Por lo mismo el servidor
devuelve marcas de tiempo y ningún segundo transcurrido: el cronómetro lo
pinta el cliente, contra la hora del servidor y no contra la suya.

### Control optimista por ESTADO, no por reloj

Cada toque manda el estado del que venía. Si el vigente es otro, 409 con la
fila actual en el cuerpo, que la tablet pinta al instante. Sin esto, la
cocinera marca LISTA y el ayudante, con una pantalla de hace tres segundos,
lo deshace sin que nadie se entere — y la matriz de transiciones sola no
protege, porque volver atrás es legal.

La tarjeta se mueve YA en la pantalla y se revierte si el servidor dice que
no. **Sin bandeja de salida, a diferencia del POS**: una venta es un hecho
LOCAL que el servidor debe acabar aceptando, pero un estado de cocina es una
verdad COMPARTIDA y viva entre varias tablets — sincronizar veinte minutos
después un «marqué lista» resucitaría un estado viejo en todas las demás
pantallas.

### La pantalla

Cuarta app de Vite (`/event-kds`), hermana del POS. El sistema visual se
extrajo a `resources/css/device-theme.css` para que las dos lo compartan en
vez de duplicarlo; el tema se fija en oscuro en el `<head>`, antes del primer
pintado, porque el POS lo aplica tras montar y hace un destello blanco que a
oscuras deslumbra.

**Sin service worker**, y es deliberado: el del POS se registra sin opción de
`scope`, así que controla todo el origen; un segundo competiría por la misma
registración y rompería de forma intermitente el arranque sin señal del POS,
que es la pieza más delicada del sistema. Y el KDS no gana nada offline: su
trabajo es enseñar lo que el servidor sabe. La contrapartida honesta es que
Chrome no ofrecerá «instalar» — es una página a pantalla completa, no una PWA
instalable.

El reloj de frescura va SIEMPRE visible y a los quince segundos sin respuesta
sale una franja roja. Una pantalla congelada que parece viva es peor que una
caída: el cocinero cree que no hay pedidos.

## Consecuencias

- El papel sigue siendo el respaldo el primer día. «Tiempo real» aquí
  significa «en cuanto el POS logre sincronizar», y con mal wifi eso pueden
  ser minutos. La tarjeta lo dice con la chapa `LLEGÓ TARDE`, que existe
  justo para que el cocinero entienda por qué el cliente ya está furioso.
- El KDS es del mundo EVENTOS. Llevarlo a `/business` exige decidir otra
  puerta (no hay comercio ni código que teclear) y relajar esas dos columnas
  NOT NULL. La columna `kds_pin_hash` queda sin usar en las sucursales.
- Un evento liquidado no admite tabletas nuevas: `EnrollKdsDevice` rechaza si
  el estado del evento está terminado.
- Regenerar el código NO apaga las tabletas ya colgadas, y rotar el PIN
  tampoco: cada una vive de su token. Por eso existe el botón rojo «Rotar PIN
  y revocar TODAS», que es el único que cierra la puerta entera.
- `Permission::PosDevicesManage` llevaba declarado sin implementación desde
  siempre; por fin lo comprueba alguien. Efecto lateral: en el panel del
  organizador solo lo tienen Owner y Admin, así que un gerente de eventos
  rota PIN pero no apaga tabletas. Si eso molesta en el montaje real, es una
  decisión de producto, no un fallo.

## Lo que queda fuera, a sabiendas

- **Estado por línea** («3 de 5 tacos hechos»). Multiplica los toques por una
  precisión que el flujo de un festival probablemente no usa.
- **Libro append-only de transiciones**. La comanda guarda quién marcó qué y
  cuándo, pero pierde el historial de los deshacer. El patrón
  `StockLevel`/`StockMovement` existe porque el stock es dinero
  reconstruible; un estado de cocina no lo es.
- **Informes de tiempos de cocina**. Los sellos se guardan desde el día uno,
  así que el informe es aditivo. Se deja fuera porque mezclar el retraso de
  red con el tiempo de preparación acaba en «la cocina de este comercio es
  lenta» cuando el problema era el wifi.
- **Cocina compartida entre varios puestos**. La consulta ya usa
  `whereIn('operating_unit_id', $device->unidadesVigiladas())` desde el día
  uno, así que la tabla pivote es aditiva. No se construye hasta confirmar
  que ese caso existe.
- **Migrar el POS a token propio**. Su puerta tiene los dos defectos de
  arriba, pero arreglarlos es tocar la puerta del dinero. Aquí solo se
  corrigió su limitador —que componía la llave con `email` cuando el endpoint
  valida `username`, así que limitaba por IP a secas y dejaba fuera a la
  sexta tablet del festival— y se documenta el resto como deuda conocida para
  que el KDS no la herede.
