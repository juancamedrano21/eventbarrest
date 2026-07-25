# ADR-004 — Foliado NCF por bloques por terminal; e-CF en fase 2

**Estado:** Aceptada — 2026-07-25

## Contexto

En República Dominicana cada venta lleva un **NCF** (folio autorizado por DGII, con
secuencias por tipo de comprobante y fecha de vencimiento). La Ley 32-23 introduce el
comprobante electrónico (**e-CF**) con calendario de obligatoriedad progresivo.

Problema central: el POS folia ventas **offline**, pero una secuencia fiscal es un
contador único — dos terminales sin red no pueden repartirse un contador en vivo.

## Decisión

### Foliado offline: bloques de folios por terminal

1. Cada secuencia NCF del tenant se administra en el servidor (`ncf_sequences`).
2. Al enrolar un terminal (o cuando su bloque se agota), el servidor le **reserva un
   sub-rango** de la secuencia (`ncf_blocks`, p. ej. 50–200 folios según volumen).
3. El terminal folia localmente dentro de su bloque, sin red, sin colisiones posibles.
4. En cada sync, el servidor registra el consumo real y **reabastece** el bloque
   cuando cae bajo un umbral.
5. Folios no usados de un terminal revocado o de un evento cerrado se **anulan**
   (nunca se reasignan), quedando el hueco documentado para auditoría.

Costo asumido: los NCF no salen en orden cronológico global (el terminal A puede
emitir 105 después de que el B emitió 130). Es consecuencia directa e inevitable del
foliado offline; la trazabilidad queda garantizada por terminal + timestamp.

### e-CF: fase 2, sobre la misma infraestructura

- El MVP emite NCF tradicional. El diseño de secuencias/bloques ya sirve para e-CF
  (las secuencias electrónicas E32/E31 se administran igual).
- La emisión e-CF será **asíncrona vía cola**: la venta se folia al momento; el XML se
  firma y envía a DGII en background con reintentos y gestión de acuses. Una venta
  jamás espera por DGII.
- Decisión pendiente (ADR futuro): integrar directo contra DGII o vía un proveedor de
  facturación autorizado. Evaluar cuando el calendario de obligatoriedad alcance a
  nuestros tenants.

## Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Foliar solo en el servidor | El POS offline no podría emitir comprobante → viola el requisito offline. |
| Foliar al sincronizar (venta sin NCF hasta tener red) | El cliente se va sin comprobante válido; riesgo legal y mala experiencia. |
| Una secuencia DGII distinta por terminal | Carga administrativa absurda para el tenant; los bloques logran lo mismo sin trámites extra. |

## Consecuencias

- (+) Foliado fiscal correcto con cero dependencia de red en el momento de la venta.
- (+) Misma mecánica sirve para NCF papel hoy y e-CF mañana.
- (−) Lógica de reabastecimiento y anulación de bloques que mantener y auditar.
- (−) Requiere validación con contador: tratamiento de folios anulados y reportes
  606/607 con numeración no consecutiva global.
