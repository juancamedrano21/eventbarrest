# ADR-002 — Multi-tenancy: base de datos compartida con `tenant_id`

**Estado:** Aceptada — 2026-07-25

## Contexto

Cada negocio (tenant) debe operar completamente aislado: sus usuarios, inventario,
ventas y configuración. El super admin necesita métricas globales entre tenants.
Esperamos muchos tenants pequeños/medianos (bares, restaurantes, organizadores de
eventos), no pocos tenants gigantes.

## Decisión

**Una sola base de datos compartida; toda tabla de negocio lleva `tenant_id`**
(single-database multi-tenancy), con:

1. **Global scope automático** en todos los modelos tenant-scoped (trait
   `BelongsToTenant`): imposible olvidar el `where tenant_id` en el código de negocio.
2. **Contexto de tenant resuelto en middleware** (por el usuario autenticado; los
   dispositivos POS llevan el tenant en su token de dispositivo).
3. **Índices compuestos** que empiezan por `tenant_id` en todas las tablas grandes.
4. **Tests de aislamiento obligatorios**: suite que verifica que ningún endpoint
   filtra datos de otro tenant (se ejecuta en CI siempre).
5. Los modelos de plataforma (tenants, planes, usuarios de plataforma) viven fuera
   del scoping.

## Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Base de datos por tenant (p. ej. stancl/tenancy multi-DB) | Aislamiento más fuerte, pero: migraciones ×N tenants, backups ×N, reportería global del super admin mucho más compleja, costo operativo alto para tenants pequeños. Con cientos de bares pequeños no compensa. |
| Esquema por tenant (PostgreSQL schemas) | Mismo problema operativo y además cambiaría el motor (el equipo domina MySQL). |

## Ruta de escape

El diseño no cierra la puerta: si un día un tenant enorme (cadena grande) necesita
aislamiento físico, se puede extraer su data a una BD dedicada porque **todas** las
tablas ya llevan `tenant_id` (el particionado lógico ya existe). No optimizamos para
eso hoy.

## Consecuencias

- (+) Operación simple: una migración, un backup, una BD que monitorear.
- (+) Reportería global del super admin con consultas directas.
- (−) El aislamiento depende de disciplina de código → se mitiga con el trait
  obligatorio + tests de aislamiento en CI.
- (−) Un tenant con carga anómala puede afectar a los demás ("noisy neighbor") →
  mitigable con rate limiting por tenant y, en el extremo, la ruta de escape.
