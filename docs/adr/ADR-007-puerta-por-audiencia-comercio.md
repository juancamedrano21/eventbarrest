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
| `/admin` | Superadmin de la plataforma | Filament (se queda) |
| `/panel` | Equipo de la CUENTA (organizador/negocio) | Blade + Preline |
| `/comercio` | Personal del comercio con rol de gestión | Blade + Preline |
| `/pos` | Cajeros | PWA Vue offline-first |

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
