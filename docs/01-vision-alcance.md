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

## Las dos modalidades

### Modalidad Comercio Propio

Negocio con operación permanente: restaurante, bar, discoteca, cafetería.

- Una o varias **sucursales**.
- Catálogo, inventario y personal por sucursal (con catálogo maestro compartido opcional).
- Operación continua: turnos, cierres diarios, reportería histórica.

### Modalidad Eventos

Organizador que monta un evento por tiempo limitado: festival, concierto, feria, temporada.

- El **evento** es un contenedor con fecha de inicio/fin y presupuesto.
- Dentro del evento se crean **puntos de venta** (mini bares / mini restaurantes),
  cada uno con su propio inventario asignado, su personal y sus terminales POS.
- Reportería consolidada del evento en tiempo real: qué punto vende más, qué se agota,
  liquidación final por punto de venta.
- Al cerrar el evento: devolución de inventario sobrante, liquidación y archivo.

Un mismo tenant puede tener **ambas modalidades activas** (ej. una discoteca que
además organiza festivales).

## Actores

| Actor | Nivel | Descripción |
|---|---|---|
| Super Admin | Plataforma | Dueño del SaaS. Gestiona tenants, planes, facturación del servicio, soporte, métricas globales. |
| Soporte plataforma | Plataforma | Acceso de solo lectura/asistencia a tenants, sin datos sensibles. |
| Dueño del negocio | Tenant | Control total de su negocio: sucursales, eventos, usuarios, configuración, reportería completa. |
| Administrador | Tenant | Gestión operativa delegada (según permisos). |
| Gerente de unidad | Unidad operativa | Administra una sucursal o un punto de venta de evento: inventario, personal, cierres. |
| Cajero / Bartender / Mesero | Unidad operativa | Opera el POS: toma órdenes, cobra, cierra su caja. |
| Almacenista | Tenant / Unidad | Compras, recepción, transferencias y conteos de inventario. |

## Alcance del MVP (fase 1)

1. Registro y gestión de tenants (alta manual desde super admin).
2. Modalidad comercio propio con una sucursal.
3. Catálogo de productos con recetas e insumos.
4. Inventario: compras, ajustes, consumo por venta.
5. POS offline-first: órdenes, pagos (efectivo/tarjeta), apertura/cierre de caja.
6. Comprobantes fiscales NCF (numeración; e-CF en fase 2).
7. Reportería básica: ventas, productos top, cierre Z diario.
8. Usuarios y roles por tenant.

## Fase 2

- Modalidad eventos completa (contenedor + puntos de venta + liquidación).
- Multi-sucursal con transferencias de inventario.
- e-CF (facturación electrónica DGII) — según calendario de obligatoriedad.
- Planes y suscripciones self-service con pasarela de pago.
- App de comandas para meseros (mismo PWA, rol distinto).

## Fase 3 (visión)

- Integración con pasarelas locales (Azul, CardNET) en el POS.
- Módulo de compras con órdenes a proveedores.
- Pronóstico de demanda y sugerencia de compra.
- API pública para integraciones (contabilidad, delivery).

## Fuera de alcance (por ahora)

- Delivery y pedidos en línea de clientes finales.
- Nómina/pago de empleados (solo control de turnos y propinas).
- Contabilidad formal (se exporta hacia sistemas contables).
