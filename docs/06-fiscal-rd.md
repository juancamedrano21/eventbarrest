# 06 — Fiscalidad República Dominicana

> ⚠️ Este documento resume lo que el sistema debe soportar. Las tasas, montos y
> calendarios deben **validarse con un contador dominicano** antes de implementar,
> y revisarse periódicamente (la normativa DGII cambia).

## Conceptos que el sistema modela

| Concepto | Qué es | Impacto en el sistema |
|---|---|---|
| **RNC** | Registro Nacional del Contribuyente (identificación fiscal del negocio y de clientes empresa) | Campo del tenant; validación de formato; requerido para B01/E31 |
| **NCF** | Número de Comprobante Fiscal — folio autorizado por DGII para cada comprobante | Secuencias autorizadas con vencimiento; el sistema folia cada venta |
| **e-CF** | Comprobante Fiscal Electrónico (XML firmado enviado a DGII, Ley 32-23) | Fase 2: firma digital, envío, acuse; calendario de obligatoriedad progresivo |
| **ITBIS** | Impuesto a la Transferencia de Bienes y Servicios — tasa general **18%** | Cálculo por línea; productos exentos configurables; desglose en ticket |
| **Propina legal** | **10%** obligatorio en bares/restaurantes (art. 228 Código de Trabajo) | Línea automática en la cuenta; configurable por unidad; reportería de propinas |

## Tipos de comprobante relevantes

| Papel (NCF) | Electrónico (e-CF) | Uso en la plataforma |
|---|---|---|
| B02 | E32 | **Consumo** — venta típica de POS a consumidor final |
| B01 | E31 | **Crédito fiscal** — cliente con RNC que pide factura |
| B04 | E34 | **Nota de crédito** — anulaciones/devoluciones después de emitido |
| B14 | E44 | Regímenes especiales |
| B15 | E45 | Gubernamental |

Orden de cálculo del ticket:

```
Subtotal (suma de líneas)
+ Propina legal 10%        (sobre subtotal, si la unidad la aplica)
+ ITBIS 18%                (sobre subtotal; líneas exentas no suman base)
= Total
```

> Nota: si el precio de carta es "ITBIS incluido" (común en bares), el sistema
> desglosa hacia atrás. Configurable por tenant: precios con impuesto incluido o no.

## Requisitos funcionales del módulo Fiscal

1. **Gestión de secuencias NCF**: alta de rangos autorizados por tipo, control de
   vencimiento, alertas de agotamiento (< N folios restantes).
2. **Foliado offline por bloques**: cada terminal recibe un sub-rango de la secuencia
   (ver [ADR-004](adr/ADR-004-facturacion-electronica.md)) para poder foliar sin red.
3. **Nota de crédito obligatoria** para anular una venta ya foliada (no se "borra").
4. **Cambio de comprobante**: un consumo (B02) puede requerir reemisión como
   crédito fiscal (B01) si el cliente lo pide con RNC — flujo soportado.
5. **Exportes 606/607** (compras/ventas) en el formato que exige DGII, por período.
6. **e-CF (fase 2)**: generación del XML, firma con certificado digital del tenant,
   envío a DGII, gestión de acuses (aceptado/rechazado) y contingencia.

## Decisiones de producto

- El MVP emite **NCF tradicional** (numeración local en tickets impresos/digitales);
  la infraestructura de secuencias ya queda diseñada para e-CF.
- Cada tenant configura sus propias secuencias (es su autorización ante DGII);
  la plataforma no comparte secuencias entre tenants jamás.
- Los tickets no fiscales ("pre-cuenta") se marcan visiblemente como
  **"NO VÁLIDO COMO COMPROBANTE FISCAL"** para evitar problemas legales.
