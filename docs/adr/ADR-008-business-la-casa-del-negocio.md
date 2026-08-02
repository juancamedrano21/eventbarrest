# ADR-008 — `/business`, la casa del negocio independiente (y el apagado de `/app`)

**Fecha**: 2026-08-02 · **Estado**: aceptada e implementada

## Contexto

El ADR-007 dejó `/business` anotado como pendiente. Mientras tanto, el mundo
del bar independiente tenía dominio completo —`BusinessAccount`, `Branch`,
ventas, inventario, recetas, arqueos, todo probado— pero ninguna puerta
propia. Su gente aterrizaba en sitios equivocados:

- El **dueño** caía en `/event-panel`, el panel del organizador de festivales,
  donde casi ninguna pantalla le servía y varias le daban 403.
- El **cajero** quedaba en un callejón sin salida: la rama de `HomeForUser`
  que lleva al POS estaba anidada dentro del `if` de comercio de evento, así
  que era inalcanzable sin `vendor_id`.

La operación real del bar vivía en `/app`, el panel Filament que el ADR-006 ya
había condenado.

## Decisión

`/business` es la puerta de la modalidad NEGOCIO, con ocho secciones:
resumen, menú, inventario, ventas, caja, sucursales, equipo y ajustes.

### La frontera vive en la puerta, no repartida

A diferencia de `/event-panel` —donde cada controlador comprueba el mundo con
su trait—, `/business` pone la frontera en el middleware `EnsureBusinessUser`:
exige `tenant instanceof BusinessAccount` (positivo, nunca por descarte, para
que una tercera modalidad futura no se cuele), corta suspensiones y **limpia
`VendorContext`**.

Esa limpieza no es decorativa. `VendorScope` falla ABIERTO: si un contexto de
comercio se colara —heredado de un job, de un `runAs` mal cerrado—, filtraría
por un `vendor_id` que en este mundo siempre es nulo y **el catálogo entero
del bar desaparecería sin un solo error**. La puerta garantiza la precondición
en vez de confiar en que nadie la rompa.

### El catálogo es de la CUENTA, no de la sucursal

Es la decisión de producto más importante, y la impone el esquema: no existe
ninguna columna que ate un producto o una categoría a una unidad operativa.
Cuelgan de `tenant_id` con `vendor_id` nulo, y el único es
`(tenant_id, vendor_key, name)` con `vendor_key = COALESCE(vendor_id, 0)`.

En consecuencia: **un solo «Mojito», a un solo precio, para todas las
sucursales**. Ni siquiera se puede repetir el nombre entre locales. Lo único
que sí va por sucursal es el **stock** (`stock_levels` y `stock_movements`
llevan `operating_unit_id`).

Por eso la pantalla de productos no tiene selector de sucursal y la de
existencias sí. Una carta o un precio por local no es un ajuste de pantalla:
es una tabla puente nueva (`product_branch`) y un cambio en el catálogo que
sirve el POS.

### No existe vínculo usuario ↔ sucursal

`users` tiene `tenant_id` y `vendor_id`, y nada más. El mundo eventos tiene un
segundo nivel de contexto (`VendorContext`); el mundo negocio **no tiene
equivalente**, y `Branch` es una unidad operativa, no un contexto.

Consecuencia asumida: en un bar con tres sucursales, cualquier gerente ve las
existencias y las ventas de las tres, y cualquier cajero puede abrir caja en
cualquiera. Si algún día hace falta «este gerente es solo de la Sucursal
Centro», hay que inventar la relación desde cero y filtrarla explícitamente en
cada consulta y en el arranque del POS: no habrá un scope global que lo haga
solo, y el principio del proyecto dice que eso se resuelve por puerta, no con
condicionales.

### La propina legal no es venta del negocio

El 10 % viaja SUMADO dentro de `orders.total_cents`, y todos los reportes
existentes suman esa columna. En RD esa propina es un pasivo con el personal:
contarla como venta infla los ingresos del dueño y falsea cualquier margen
contra el costo de inventario.

`SalesSummary` separa las cuatro cifras —cobrado, devuelto, propina y venta—
y mantiene la identidad **ventas + propina + devuelto = cobrado**.

Los reembolsos obligaron a una decisión: `refunds` guarda un importe plano,
sin desglose, así que no hay forma de saber qué parte de una devolución era
propina. Se reparte **en la misma proporción en que se devolvió la orden**:
devolver la mitad de una venta devuelve la mitad de su propina. Es la única
lectura posible sin inventar un dato que nadie registró. Sin ese prorrateo,
una venta con propina reembolsada entera daba **ventas negativas**.

Dos preguntas distintas, dos consultas distintas, y conviene no confundirlas:

| Pregunta | Respuesta | Corta por |
|---|---|---|
| De lo que vendí en este período, ¿cuánto me quedé? | `SalesSummary` | `paid_at`, arrastrando todos los reembolsos de esas órdenes |
| ¿Cuánto dinero salió hoy de la gaveta? | `NetSales` | el día de la devolución, para cuadrar con el arqueo |

### Tres pantallas que no existían en ningún panel

- **Arqueos.** Cerrar caja solo se alcanzaba por `POST /api/pos/sessions/{id}/close`.
  El histórico de turnos —esperado, contado, diferencia— no se podía mirar en
  ninguna parte del sistema, y es lo primero que un dueño revisa cada mañana.
  Se MIRA desde el panel; abrir y cerrar sigue ocurriendo en el POS, junto al
  dinero: cerrar un turno desde una oficina, sin contar los billetes, no es un
  arqueo.
- **Ajustes fiscales.** `tenants.itbis_mode` solo se editaba desde
  `/saas-admin`, así que todo bar facturaba con el impuesto incluido por el
  valor por defecto — correcto para una barra, equivocado para un restaurante
  que cobra el 18 % por fuera. Es la casa de `fiscal.manage`, un permiso que
  llevaba tiempo definido sin que nadie lo comprobara.
- **Ajuste de conteo, merma, traslado, umbral y libro mayor**, que solo
  existían en el Filament condenado.

### Los traits del catálogo se duplican a propósito

`HandlesBusinessCatalog` y `HandlesBusinessInventory` son gemelos de los del
mundo eventos, no su reutilización. Allí cada operación entra en el comercio
con `runAs`; aquí no hay comercio en el que entrar. Duplicar el gemelo es lo
que evita que estas dos lecturas del mundo acaben decidiéndose con un `if` en
código compartido — el principio del proyecto desde julio de 2026.

Lo que sí se comparte es lo que no tiene mundo: `NetSales`, `ResolveItbisMode`,
`StockLedger`, las Actions de inventario y la vista del detalle de venta, que
sirve a las tres puertas cambiando quién la enmarca (`$layoutVenta`), a dónde
vuelve (`$volver`) y de quién es la venta (`$titular`).

### Layout propio y fijo

`/business` hace `@extends('business.layout')`, sin la indirección
`$panelLayout` de `/event-panel`. Ese salto apunta a un tema Preline Pro que
vive fuera de git, así que las pantallas se ven distintas en la máquina de
quien lo tiene y en CI, y las gráficas solo existen en una de las dos. Una
puerta, un layout. La gráfica de 14 días se dibuja con CSS, sin depender de
ninguna librería externa.

## El apagado de `/app`

Retirarlo estaba bloqueado por **dos** paneles, no por uno: además de todo el
mundo negocio, `/app` era el único sitio donde se podía editar un evento y su
estado (sin lo cual un festival no se podía cerrar ni liquidar), editar un
puesto, renegociar la comisión de una participación, quitar un comercio de un
evento y cambiar el rol de alguien de su equipo. Se construyeron esas cinco
capacidades en `/event-panel` antes de borrar nada.

Antes del borrado se rescató lo que sus ~80 tests protegían, que eran reglas
de negocio y no de Filament: la matriz observada de rol × pantalla, el
aislamiento de identidad y las reglas de equipo. Y se limpió `tests/Pest.php`:
el helper `signInTo()` fijaba el panel `'app'` dentro, así que borrarlo sin
tocarlo habría tumbado los 45 tests de `/saas-admin` por daño colateral.

**Esto no desacopla el dominio de Filament.** El paquete se queda por
`/saas-admin`, y 19 enums de `app/Domains/` implementan sus contratos
`HasLabel`/`HasColor`. Sacarlo del todo es otro trabajo, mecánico pero ancho.

## Consecuencias

- Las puertas quedan: `/saas-admin` (Filament), `/event-panel`,
  `/event-vendor`, `/event-pos`, `/business`, `/pos`, y `/entrar` como entrada
  única.
- Un cambio de comportamiento que los tests viejos consagraban al revés: quien
  no tiene capacidades de gestión pero sí puede operar caja ya no recibe un
  403 seco, se le manda al POS. Un callejón sin salida no es una medida de
  seguridad.
- Corregido de paso: el cajero de un comercio de evento iba a `/pos`, donde la
  API lo rechaza por modalidad; ahora va a `/event-pos`.
- La lista de capacidades de gestión, escrita literal en dos sitios y camino
  de un tercero, vive en el enum `Permission` junto a `accountOnly()` y
  `posOnly()`.

## Lo que queda pendiente

- Liquidación de eventos: el estado de cuenta por comercio y el corte.
- Fiscalidad DGII: secuencias NCF, foliado offline, nota de crédito para los
  reembolsos, exportes 606/607 y e-CF.
- Comandas a cocina y barra: la clasificación ya decide el despacho, falta
  imprimir. Y `OperatingUnitKind` promete decidir qué catálogo ve el POS, pero
  `PosCatalogController` todavía no mira `kind`.
- Anular una orden antes del cobro no tiene endpoint: `VoidOrder` existe en el
  dominio y no está expuesto.
- Un backstop en la base de datos para el sobregiro de reembolsos: hoy lo
  garantiza el lock, no una restricción.
