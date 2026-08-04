# ADR-003 — POS offline-first con log de operaciones idempotente

**Estado:** Aceptada — 2026-07-25

## Contexto

Requisito del producto: el POS debe seguir vendiendo sin internet (discotecas con mala
señal, eventos masivos con red saturada). Hay que elegir cómo se sincroniza y cómo se
evitan duplicados y conflictos.

## Decisión

**Outbox local de operaciones + upsert idempotente por UUID en el servidor.**

1. El POS guarda cada operación de venta (orden, ítems, pagos, movimientos de caja)
   en IndexedDB con **UUID generado en el cliente** y la encola en un *outbox*.
2. Un proceso de fondo envía el outbox por lotes a `POST /api/v1/sync/push` cuando hay
   red (y reintenta con backoff). El servidor hace **upsert por UUID**: reenviar un
   lote completo tras un timeout no duplica nada.
3. El catálogo y la configuración bajan como **snapshot versionado**
   (`GET /sync/pull?catalog_version=N` devuelve solo el delta).
4. **No hay edición concurrente**: una orden pertenece al terminal que la creó hasta
   que sincroniza. Por diseño, las ventas no pueden entrar en conflicto.
5. El stock local del POS es **indicativo** (para alertas); el stock real se consolida
   en el servidor al sincronizar. Un stock negativo tras consolidar genera alerta de
   descuadre, nunca bloquea ventas ya cobradas.

## Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Siempre online (web pura) | Viola el requisito: red caída = evento parado. |
| CRDTs / motor de sync genérico (PouchDB/CouchDB, ElectricSQL) | Potencia que no necesitamos: nuestras escrituras offline son *append-only* por terminal, sin edición concurrente. Un outbox idempotente es más simple de razonar, depurar y auditar — importante en datos con implicación fiscal. |
| Colas de mensajes en el dispositivo (sync bidireccional completo) | El único dato bidireccional real es el catálogo, y con snapshot versionado basta. |

## Consecuencias

- (+) Modelo mental simple: "el POS emite hechos; el servidor los absorbe".
- (+) Idempotencia trivial de testear (reenviar lote ⇒ mismo estado).
- (+) Auditoría natural: cada hecho conserva `sold_at` (hora del POS) y `synced_at`.
- (−) Funciones multi-terminal (mesa compartida entre estaciones) requieren red;
  se documenta como límite explícito del modo offline.
- (−) El foliado fiscal offline necesita reserva de rangos por terminal → resuelto
  en [ADR-004](ADR-004-facturacion-electronica.md).
