# 01 — Visión y Alcance

## El problema

Los bares, restaurantes y discotecas manejan inventario, ventas y personal con
herramientas fragmentadas (o con papel). En eventos (festivales, conciertos, ferias)
el problema se multiplica: se montan barras y cocinas temporales sin ningún control
centralizado de inventario ni de ventas, con conectividad de red poco confiable.

## La solución

Plataforma SaaS multi-tenant que da a cada negocio control total de:

- **Inventario**: insumos, recetas (escandallo), stock por ubicación, compras,
  transferencias, mermas, consumo automático por venta.
- **Ventas**: punto de venta (POS) táctil, offline-first, mesas/zonas, comandas,
  múltiples métodos de pago, arqueo y cierre de caja.
- **Reportería**: ventas por producto/categoría/hora/empleado, márgenes,
  rotación de inventario, comparativos entre sucursales o entre puntos de un evento.
- **Facturación fiscal (RD)**: emisión de comprobantes NCF / e-CF según normativa DGII.

## Los dos tipos de cuenta

No son dos modalidades de una misma cuenta: son **dos mundos cerrados** que no
comparten datos. El tipo se decide al dar de alta la cuenta y no cambia.

### Cuenta de Negocio

Restaurante, bar, discoteca o cafetería con operación permanente.

- Una o varias **sucursales**.
- Catálogo, inventario y personal propios (con catálogo maestro compartido opcional).
- Operación continua: turnos, cierres diarios, reportería histórica.

### Cuenta de Organizador de eventos

Productora que monta festivales, conciertos, ferias o temporadas.

- Dentro de su cuenta crea cada **evento**: un contenedor con fecha de inicio/fin
  y presupuesto.
- Dentro de cada evento se crean sus **puntos de venta** (barras y cocinas), cada
  uno con su inventario asignado, su personal y sus terminales POS.
- Reportería consolidada del evento en tiempo real: qué punto vende más, qué se
  agota, liquidación final por punto de venta.
- Al cerrar el evento: devolución de inventario sobrante, liquidación y archivo.
- El organizador conserva el histórico entre ediciones ("el año pasado vendimos X")
  y reutiliza su equipo, pero **cada evento es un mundo cerrado por dentro**.

### La regla que separa los dos mundos

> Un evento **nunca** comparte datos con un negocio de la plataforma.

Si un negocio que ya es cliente nuestro (por ejemplo, *Bar del Malecón*) quiere
poner una barra en un festival, esa barra **se crea dentro del evento**, con su
propio catálogo, su propio inventario y su propio personal. Aunque lleve el mismo
nombre, no comparte nada con su negocio: ni productos, ni stock, ni ventas, ni
reportería. El aislamiento es el mismo que entre dos clientes distintos, porque
técnicamente **son cuentas distintas**.

Lo que sí comparten los dos mundos es el **código**: una sucursal y una barra de
festival son ambas *unidades operativas* (ver [03 — Modelo de datos](03-modelo-datos.md)),
así que POS, inventario y reportería se construyen una sola vez.

## Actores

| Actor | Nivel | Descripción |
|---|---|---|
| Super Admin | Plataforma | Dueño del SaaS. Gestiona cuentas, planes, facturación del servicio, soporte, métricas globales. |
| Soporte plataforma | Plataforma | Acceso de solo lectura/asistencia a cuentas, sin datos sensibles. |
| Dueño | Cuenta | Control total de su cuenta: sucursales (negocio) o eventos (organizador), usuarios, configuración, reportería completa. |
| Administrador | Cuenta | Gestión operativa delegada (según permisos). |
| Gerente de unidad | Unidad operativa | Administra una sucursal o un punto de venta de evento: inventario, personal, cierres. |
| Cajero / Bartender / Mesero | Unidad operativa | Opera el POS: toma órdenes, cobra, cierra su caja. |
| Almacenista | Cuenta / Unidad | Compras, recepción, transferencias y conteos de inventario. |

## Alcance del MVP (fase 1)

1. Registro y gestión de cuentas (alta manual desde super admin).
2. Cuenta de negocio con una sucursal.
3. Catálogo de productos con recetas e insumos.
4. Inventario: compras, ajustes, consumo por venta.
5. POS offline-first: órdenes, pagos (efectivo/tarjeta), apertura/cierre de caja.
6. Comprobantes fiscales NCF (numeración; e-CF en fase 2).
7. Reportería básica: ventas, productos top, cierre Z diario.
8. Usuarios y roles por cuenta.

## Fase 2

- Cuentas de organizador: eventos, puntos de venta, asignación de inventario y liquidación.
- Multi-sucursal con transferencias de inventario.
- e-CF (facturación electrónica DGII) — según calendario de obligatoriedad.
- Planes y suscripciones self-service con pasarela de pago.
- App de comandas para meseros (mismo PWA, rol distinto).

## Fase 3 (visión)

- Pagos integrados en el POS vía **SDK de Portal sobre terminales SUNMI (P2, P3 MIX)**,
  empaquetando la misma app Vue como APK con Capacitor (ver ADR-005); evaluar además
  Azul/CardNET.
- Módulo de compras con órdenes a proveedores.
- Pronóstico de demanda y sugerencia de compra.
- API pública para integraciones (contabilidad, delivery).

## Fuera de alcance (por ahora)

- Delivery y pedidos en línea de clientes finales.
- Nómina/pago de empleados (solo control de turnos y propinas).
- Contabilidad formal (se exporta hacia sistemas contables).
