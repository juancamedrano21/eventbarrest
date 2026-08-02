# ADR-007 — Una puerta por audiencia: `/comercio`, el panel privado del comercio

**Fecha**: 2026-08-01 · **Estado**: aceptada e implementada (hito 1)

## Contexto

El plan original (ADR-006) contemplaba migrar las pantallas del personal de
comercio desde Filament (`/app`) hacia `/panel`. Eso mezclaba dos audiencias
en una misma puerta con guards cruzados: el organizador (nivel cuenta) y el
encargado (nivel comercio) tienen mundos, permisos y navegación distintos.

## Decisión

Cada audiencia tiene su propia puerta, con su propio guard fail-closed:

| Puerta | Audiencia | Stack |
|---|---|---|
| `/saas-admin` | Superadmin de la plataforma | Filament (se queda) |
| `/event-panel` | Organizador del evento | Blade + Preline |
| `/event-vendor` | Comercio dentro del evento | Blade + Preline |
| `/event-pos` | Cajero de un puesto de evento | PWA Vue offline-first |
| `/business` | Bar/restaurante independiente | Blade + Preline (pendiente) |
| `/pos` | Cajero de una sucursal | PWA Vue offline-first |

- **`/comercio` es singular a propósito**: un usuario pertenece a UN comercio
  (doc 04); su comercio es implícito por su usuario, jamás elegido por URL.
- **Entrada por capacidades, no por nombre de rol**: quien solo opera el POS
  rebota a `/pos` (`onlyOperatesThePos()`); usuarios de cuenta rebotan a
  `/panel`; suspensiones (cuenta o comercio) cortan con 403. El middleware
  `EnsureComercioUser` ejecuta la matriz de rebotes.
- **Las operaciones se comparten, no se duplican**: los formularios de
  catálogo/inventario viven una sola vez (`HandlesVendorCatalog`,
  `HandlesVendorInventory`) y las vistas de pestañas son parciales
  parametrizados por URLs (`resources/views/vendors/tabs/*`) — el
  organizador llega con `/panel/comercios/{id}`, el encargado con su
  comercio implícito, y ambos renderizan lo mismo.
- Los usuarios del comercio se siguen creando desde `/panel` (perfil del
  comercio → Usuarios) con roles `kind=vendor`; el superadmin puede crear
  roles nuevos y la entrada se deriva sola de sus permisos.

## Consecuencias

- `/app` (Filament de cuentas) pierde su última razón de existir: cuando
  `/comercio` alcance paridad total (equipo, movimientos de inventario,
  reportes de unidad), se apaga entero.
- El login sigue siendo el de `/app` por ahora; un login propio con rebote
  por audiencia es un pendiente conocido.
- Hito 1 entregado: resumen, menú (modal de ítem + escandallo), ventas con
  detalle, inventario con compras. Pendiente: equipo, cierres de caja del
  encargado, reportes `reports.view_unit` con series.


## Adenda (2026-08-05): una puerta por MODALIDAD, además de por audiencia

Decisión del usuario, y corrige una recomendación equivocada de esta
sesión: se propuso un `/panel` único que se adaptara al tipo de cuenta, lo
cual violaba su preferencia arquitectónica de 2026-07-27 — «las reglas de
los mundos NO deben ser condicionales en código compartido». Los datos le
dieron la razón: `/panel` ya exigía cuenta de organizador en todas sus
pantallas y solo el dashboard se adaptaba, con ocho condicionales.

Las puertas pasan a nombrarse por modalidad, con el prefijo `event-` para
el mundo de los festivales y sin prefijo para el mundo del negocio:

- `/saas-admin` — la plataforma.
- `/event-panel`, `/event-vendor`, `/event-pos` — modalidad eventos.
- `/business` (pendiente de construir) y `/pos` — modalidad negocio.

`/vendor` se descartó para el mundo negocio: en el código `Vendor` ES el
comercio de evento (modelo, `vendor_id`, scopes, traits), y la colisión
semántica habría costado más que el renombrado.

**Los dos POS comparten motor.** Cada puerta tiene su URL, su manifiesto y
su identidad instalable, y el login rechaza al cajero del mundo
equivocado con un mensaje que le dice a dónde ir. Pero el código offline
—outbox, sincronización idempotente, arqueos— es UNO: duplicar la pieza
más delicada del sistema para mantener dos copias en paralelo sería un
error caro, y en Android significaría dos apps para el mismo trabajo. Si
algún día las modalidades divergen de verdad en la caja, el punto de corte
es el componente de pantalla, no el motor.
