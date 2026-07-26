# Plataforma SaaS de Gestión para Bares, Restaurantes y Eventos

Documentación de arquitectura y diseño del proyecto.

## Índice

| Documento | Contenido |
|---|---|
| [01 — Visión y Alcance](01-vision-alcance.md) | Qué es la plataforma, modalidades, actores, alcance del MVP |
| [02 — Arquitectura del Sistema](02-arquitectura-sistema.md) | Diagramas C4 (contexto y contenedores), stack, módulos |
| [03 — Modelo de Datos](03-modelo-datos.md) | Diagrama entidad-relación del núcleo del sistema |
| [04 — Roles y Permisos](04-roles-permisos.md) | Jerarquía de usuarios y matriz de permisos |
| [05 — POS Offline y Sincronización](05-pos-offline-sync.md) | Estrategia offline-first del punto de venta |
| [06 — Fiscalidad República Dominicana](06-fiscal-rd.md) | NCF, e-CF (DGII), ITBIS, propina legal |
| [07 — Diseño de UI y Paneles](07-diseno-ui.md) | Las tres superficies, Filament, UX del POS, marca blanca |
| [08 — Estructura del Proyecto](08-estructura-proyecto.md) | Stack con versiones (Laravel 13, Filament 5), carpetas, convenciones |

## Decisiones de Arquitectura (ADRs)

| ADR | Decisión |
|---|---|
| [ADR-001](adr/ADR-001-stack-laravel.md) | Monolito Laravel full-stack; PWA solo para el módulo POS |
| [ADR-002](adr/ADR-002-multi-tenancy.md) | Multi-tenancy con base de datos compartida y `tenant_id` |
| [ADR-003](adr/ADR-003-pos-offline.md) | POS offline-first con log de operaciones idempotente |
| [ADR-004](adr/ADR-004-facturacion-electronica.md) | Estrategia de facturación NCF / e-CF con bloques por terminal |
| [ADR-005](adr/ADR-005-pos-sunmi-capacitor.md) | POS: PWA web + wrapper Capacitor para SUNMI (SDK Portal, impresora integrada) |

## Concepto central

Dos tipos de cuenta, **dos mundos cerrados** que no comparten datos:

```
Plataforma (Super Admin)
├── Cuenta de Negocio       → Sucursales                    (operación permanente)
└── Cuenta de Organizador   → Evento → Puntos de venta      (operación temporal)
                                       (barras y cocinas)
```

**Un evento nunca comparte datos con un negocio.** Si un negocio cliente quiere poner
una barra en un festival, esa barra se crea *dentro del evento*, con su propio
catálogo, inventario y personal: aunque lleve el mismo nombre, no comparte nada.

Lo que sí comparten los dos mundos es el **código**: tanto la sucursal como el punto
de venta de un evento son especializaciones del mismo concepto, la **Unidad
Operativa**. Todo lo transaccional (ventas, inventario, cajas, personal en turno)
cuelga de una unidad operativa, así que POS, inventario y reportería se construyen
una sola vez.
