# Plataforma SaaS de Gestión para Bares, Restaurantes y Eventos

Documentación de arquitectura, decisiones y estado del proyecto.

**El código vive en el repo `eventbarrest`** (aquí, en `../eventbarrest`). Su README
tiene la puesta en marcha: MAMP, migraciones, seeders de demo y los tres candados del
CI (`pint`, `phpstan`, `pest`).

## Cómo leer esta documentación

No todo está igual de fresco. Antes de fiarte de un documento, mira esta columna:

| Documento | Contenido | Estado |
|---|---|---|
| [01 — Visión y Alcance](01-vision-alcance.md) | Qué es la plataforma, los dos tipos de cuenta, actores, fases | Vigente |
| [02 — Arquitectura del Sistema](02-arquitectura-sistema.md) | Diagramas C4, stack, módulos | **v0.1 (25-jul) — desactualizado** |
| [03 — Modelo de Datos](03-modelo-datos.md) | Diagrama entidad-relación del núcleo | Vigente en el núcleo; sin comercios ni ventas |
| [04 — Roles y Permisos](04-roles-permisos.md) | Jerarquía de usuarios y matriz de permisos | **v0.1 (25-jul) — desactualizado** |
| [05 — POS Offline y Sincronización](05-pos-offline-sync.md) | Estrategia offline-first del punto de venta | **v0.1 (25-jul) — el código sincroniza distinto** |
| [06 — Fiscalidad República Dominicana](06-fiscal-rd.md) | NCF, e-CF (DGII), ITBIS por producto y modalidad, propina legal | Vigente — **léelo antes de tocar dinero** |
| [07 — Diseño de UI y Paneles](07-diseno-ui.md) | Superficies, UX del POS, marca blanca | Parcial: las puertas las manda el ADR-007 |
| [08 — Estructura del Proyecto](08-estructura-proyecto.md) | Stack con versiones, carpetas, convenciones | Vigente |
| [09 — Convergencia con la spec de festivales](09-analisis-spec-festival.md) | Qué de la spec del equipo ya existe y qué no | Análisis, 27-jul |

Cuando un documento y el código se contradigan, **manda el código**; el documento está
pendiente de actualizar y agradecemos el aviso.

## Decisiones de Arquitectura (ADRs)

| ADR | Decisión | En el código |
|---|---|---|
| [ADR-001](adr/ADR-001-stack-laravel.md) | Monolito Laravel full-stack; PWA solo para el POS | Sí |
| [ADR-002](adr/ADR-002-multi-tenancy.md) | Multi-tenancy con base compartida y `tenant_id` | Sí, con suite de aislamiento como contrato |
| [ADR-003](adr/ADR-003-pos-offline.md) | POS offline-first con log de operaciones idempotente | Sí, **implementado distinto** (ver abajo) |
| [ADR-004](adr/ADR-004-facturacion-electronica.md) | NCF por bloques por terminal; e-CF en fase 2 | **No — decidido, sin construir** |
| [ADR-005](adr/ADR-005-pos-sunmi-capacitor.md) | PWA + wrapper Capacitor para SUNMI | Solo la PWA; el wrapper no existe |
| [ADR-006](adr/ADR-006-panel-app-blade-preline.md) | Los paneles de cuenta migran de Filament a Blade + Alpine + Preline | Sí |
| [ADR-007](adr/ADR-007-puerta-por-audiencia-comercio.md) | Una puerta por audiencia y por modalidad (con adenda del 05-ago) | Sí, salvo `/business` |

El ADR-003 se implementó sin lotes: el POS envía **orden por orden** a
`POST /api/pos/orders` con un `client_ref` (UUID del cliente) como clave de
idempotencia, y baja el catálogo completo con `GET /api/pos/catalog`. No hay
`sync/push` por lotes ni delta versionado del catálogo; el ADR describe el destino,
no lo que corre hoy.

## Concepto central

Dos tipos de cuenta, **dos mundos cerrados** que no comparten datos:

```
Plataforma (superadmin)
├── Cuenta de Negocio      → Sucursales                          (operación permanente)
└── Cuenta de Organizador  → Comercios ──┐                       (operación temporal)
                            └ Eventos ───┴→ Puestos del comercio
                              (participación con comisión)
```

**El comercio es el nivel que hay que entender.** El organizador da de alta un
comercio una sola vez (`vendors`) y lo invita a cada evento con la comisión pactada
(`event_vendor.commission_bps`). Los puestos —barras y cocinas— cuelgan del comercio
dentro del evento. El comercio conserva su catálogo, su inventario, su equipo y su
histórico entre ediciones; el organizador ve el **rendimiento** de cada uno, no opera
por él.

Ese segundo nivel de pertenencia es una columna, `vendor_id`, y está en todo lo que
un comercio maneja por su cuenta: categorías, productos, insumos, unidades operativas,
usuarios, órdenes y reembolsos. Los índices únicos se recompusieron para incluirlo, así
que dos comercios del mismo evento pueden tener cada uno su «Mojito» sin chocar. En
cuentas de negocio `vendor_id` es nulo: ahí el aislamiento por cuenta basta.

**Un evento nunca comparte datos con un negocio de la plataforma.** Si un bar que ya
es cliente nuestro quiere poner una barra en un festival, esa barra es un **comercio
de la cuenta del organizador**: aunque lleve el mismo nombre, no comparte productos,
ni stock, ni ventas, ni reportería con su negocio. Técnicamente son cuentas distintas.

Lo que sí comparten los dos mundos es el **código**: tanto la sucursal como el puesto
de un evento son especializaciones del mismo concepto, la **unidad operativa**. Todo lo
transaccional (ventas, inventario, cajas, personal en turno) cuelga de una unidad
operativa, así que POS, inventario y reportería se construyen una sola vez.

## Las puertas: una por audiencia y por modalidad

`/` no es un panel, es un desvío: con sesión te manda a tu puerta, sin ella a `/entrar`.
La entrada es única (una sola pantalla de login) y una sola pieza, `HomeForUser`, decide
a dónde va cada quien — por **capacidades**, no por nombre de rol.

| Puerta | Quién entra | Stack | Estado |
|---|---|---|---|
| `/saas-admin` | Superadmin de la plataforma | Filament | Construida (se queda en Filament: herramienta interna) |
| `/event-panel` | Organizador del evento | Blade + Preline | Construida |
| `/event-vendor` | Personal del comercio | Blade + Preline | Construida (hito 1) |
| `/event-pos` | Cajero de un puesto de evento | PWA Vue offline | Construida |
| `/pos` | Cajero de una sucursal | PWA Vue offline | Construida |
| `/business` | Bar o restaurante independiente | Blade + Preline | Construida |

`/app`, el panel Filament de cuentas, **se apagó** el 2 de agosto de 2026
([ADR-008](docs/adr/ADR-008-business-la-casa-del-negocio.md)).

El comercio de un usuario es **implícito por su usuario, jamás elegido por URL**: por eso
`/event-vendor` es singular y no lleva id. Los dos POS tienen URL, manifiesto e identidad
instalable propios, pero **comparten motor**: duplicar el offline sería el error más caro
del sistema.

## Qué está construido

359 tests pasando (`vendor/bin/pest --parallel`), incluida la suite de aislamiento
multi-tenant que corre en cada push.

**Ventas.** El dominio completo y cerrado: sesiones de caja con una sola abierta por
unidad (índice único con columna generada), órdenes con sus líneas y sus cobros, y las
acciones `OpenCashSession`, `PlaceOrder`, `PayOrder`, `VoidOrder`, `RefundOrder` y
`CloseCashSession`. Todo el dinero en centavos enteros. **La historia es inmutable**:
las líneas congelan nombre y precio del producto, así que cambiar el catálogo no
reescribe lo vendido.

**Número de orden legible.** `P0041`: letra del canal (POS, móvil, web) más una serie
por comercio —por cuenta en el mundo negocio—, tomada dentro de la transacción de la
venta para que no queden huecos. El UUID sigue existiendo, pero para idempotencia; esto
es lo que el cliente dicta por teléfono.

**ITBIS.** Por producto (un producto declara si está exento) y con desglose **congelado
línea a línea**. Y con modalidad: incluido en el precio (se extrae ×18/118 y el total no
crece) o por fuera (se suma al cobrar). La modalidad la declara el comercio y, si no, su
cuenta — un comercio de evento es un negocio tercero y puede cobrarlo por fuera aunque
el organizador lo incluya. Detalle completo en el [doc 06](06-fiscal-rd.md).

**Comisión del organizador congelada por venta.** Cada orden guarda los puntos básicos
pactados en el momento de venderse. Renegociar la comisión o desinvitar al comercio
jamás reescribe lo ya cobrado.

**Reembolsos.** Con permiso propio (`sales.refund`). La venta no se edita nunca: el
dinero que vuelve es un hecho nuevo que la referencia, como manda la contabilidad y como
exigirá la DGII (será una nota de crédito B04). Sale de la caja **abierta** de quien
devuelve, que es el arqueo que tiene que cuadrar; el inventario no se repone.

**POS offline (PWA).** Vue 3 sobre Dexie/IndexedDB: catálogo local, bandeja de salida
con estados visibles al cajero (pendiente, sin caja, error, sincronizada, descartada) y
reintento. El service worker precachea solo el cascarón — los datos nunca pasan por la
caché. API con Sanctum bajo `/api/pos`: `bootstrap`, `catalog`, `sessions`, `orders`,
`sales` y `sales/{order}/refund`.

**Identidad.** Login propio en `/entrar` con rebote por audiencia. 20 permisos fijos en
código (cada uno corresponde a una capacidad que alguien comprueba) y roles como
**plantillas editables en base de datos** que el superadmin compone; los permisos de
administración de cuenta no pueden llegar al personal de un comercio ni siquiera a
través de un rol nuevo.

**Catálogo e inventario.** Productos con receta (escandallo), insumos, existencias por
unidad, compras, traslados, ajustes y mermas, con consumo automático al vender.
Auditoría de cambios de producto (precio incluido) con activitylog.

**Reportería.** Dashboard del organizador con ventas del día y del período, cajas
abiertas, serie diaria, desglose por comercio y comisión por evento. Los días se cortan
en **hora del negocio** (`America/Santo_Domingo`): una venta de las 9 de la noche es del
día que el bar vivió, no del que dice UTC.

## Qué NO está construido

Para que nadie lo busque en el código:

- **Fiscalidad NCF / e-CF.** Decidida en el ADR-004, sin una línea escrita: no hay
  secuencias, ni bloques, ni comprobantes. Lo único que existe es el permiso
  `fiscal.manage`.
- **La puerta `/business`** del mundo negocio (el POS de sucursal sí funciona).
- **Liquidación del evento.** `events.settle` existe como permiso, no como pantalla.
- **Reportes de unidad** (`reports.view_unit`) con series, y los cierres de caja del
  encargado en `/event-vendor`.
- **Mesas, comandas y KDS**, e impresión de tickets.
- **Wrapper Capacitor / SUNMI** y pagos integrados (ADR-005, fase 3).
- **Wallet cashless** (ver el [doc 09](09-analisis-spec-festival.md)).
- **Sync de catálogo por delta versionado**: hoy baja completo.

## Archivos históricos

`ProyectoRest-arquitectura-v0.1.pdf` y los dos HTML de revisión son de julio y **no se
mantienen**. Se conservan como registro de por dónde iba el diseño; para saber cómo
opera el sistema, esta página y los ADRs 006 y 007.
