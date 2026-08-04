# 09 — Análisis de convergencia: spec "festival de comida" vs. arquitectura actual

**Estado:** Análisis — 2026-07-27. Compara la spec de festivales de comida (referencia
TOI) elaborada por el equipo con la arquitectura documentada (docs 01-08) y el código
construido (hitos 1-7).

## Conclusión ejecutiva

La spec **no describe un proyecto distinto**: describe un subconjunto del mundo
"organizador de eventos" que ya está construido, más tres piezas que no tenemos
(wallet cashless, liquidación monetaria con comisiones, KDS/mesas). A la inversa,
la spec no cubre tres cosas que en RD son innegociables y ya tenemos: fiscalidad
DGII, operación offline e inventario.

## Coincidencias (decisiones validadas por ambas partes)

- **Multi-tenancy**: single-DB + tenant_id + global scope + middleware — idéntico a
  lo construido (nosotros además: fail-closed, builders blindados, suite de
  aislamiento como contrato).
- **Estructura**: organizador → evento → puestos = nuestro mundo organizador →
  eventos → puntos de venta.
- **Roles**: su lista mapea 1:1 con la matriz del doc 04.
- **Caja por turno** con apertura/cierre/diferencia: es nuestro `CASH_SESSIONS`
  (hito de ventas).
- **Orden** con subtotal/ITBIS/propina: igual, nosotros añadimos NCF.

## Gaps nuestros (lo que la spec aporta)

| # | Pieza | Tamaño | Encaje propuesto |
|---|---|---|---|
| 1 | **Wallet cashless** (recarga única, pago en cualquier puesto, QR/NFC) | Grande | Fase propia del mundo eventos, tras el POS. Integrable con boletería |
| 2 | **Liquidación monetaria con comisiones** (vendido − comisión = pago al puesto) | Media | Módulo de liquidación del evento (complementa la liquidación de inventario ya diseñada) |
| 3 | **KDS + estados por ítem** (pendiente → preparación → listo → entregado) | Media | Entra en el hito ventas/POS; el KDS necesita el mismo backend de comandas |
| 4 | **Estación por producto** (N estaciones por puesto, impresora por estación) | Chica | Refina nuestro `dispatch` de categoría: estación configurable por unidad, producto hereda de categoría con override |
| 5 | **Mapa interactivo de mesas** (drag & drop, formas, zonas, tiempo real) | Media | Fase "modo restaurante" del POS |
| 6 | **Integración boletería** (mismo login, misma base de clientes) | Estratégica | Iniciativa aparte: SSO/base compartida. Conversar con el autor de la spec |
| 7 | **Cliente final como actor** (app pública, wallet, pedidos) | Grande | Llega junto con el wallet |

## Ventajas nuestras (lo que la spec no cubre)

1. **Fiscalidad RD** (NCF/e-CF, bloques por terminal, 606/607) — en la spec solo
   aparece "itbis". Bloqueante legal en RD.
2. **Offline-first** — la spec asume conectividad total (WebSockets para mesas y
   KDS). En festival con red saturada, ese diseño deja de vender; el nuestro no
   (ADR-003/005). El KDS que adoptemos debe degradar a impresión offline.
3. **Inventario completo** — la spec no tiene inventario (solo `disponible` bool).
   Nosotros: insumos, escandallo, costo promedio, mermas, libro mayor inmutable.
4. **Mundo negocios** — bares/restaurantes permanentes con el mismo código.
5. **Pagos integrados SUNMI/Portal** (ADR-005) y seguridad multi-tenant auditada.

## La decisión de producto pendiente

**¿Quién opera los puestos de un evento?**

- Modelo actual nuestro: el organizador (los puntos son suyos, su equipo).
- Modelo de la spec: puestos = terceros independientes con sus usuarios y
  liquidación por comisión (marketplace, estilo TOI).

Convergencia sin romper nada: (a) usuarios asignables **a nivel de unidad** — ya
previsto en el doc 04 y necesario para el POS — de modo que el equipo de un puesto
solo vea su punto de venta; (b) liquidación con comisión por punto. El evento sigue
siendo un mundo cerrado: el "puesto tercero" es un punto de venta cuyo equipo tiene
alcance de unidad, no una cuenta nueva.

## Ruta recomendada

1. **Hito ventas/POS** (ya siguiente): incorporar de la spec la estación por
   producto, los estados por ítem y los usuarios por unidad.
2. **Fase eventos**: liquidación monetaria con comisiones.
3. **Fase cashless**: wallet + cliente final + integración boletería.
4. **Fase restaurante**: mapa de mesas interactivo.
