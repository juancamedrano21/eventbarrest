# ADR-005 — Distribución del POS: PWA web + wrapper Capacitor para SUNMI

**Estado:** Aceptada — 2026-07-25

## Contexto

El plan de producto incluye cobrar con tarjeta de forma integrada usando el **SDK de
Portal**, que corre nativo en terminales **SUNMI P2 y P3 MIX** (equipos Android con
lector EMV/NFC e impresora térmica integrados). A la vez, el POS debe seguir
funcionando como aplicación web en tablets, iPads y PCs genéricas.

El riesgo a evitar: terminar con dos POS (uno web y uno Android) que dupliquen toda
la lógica de ventas, offline y fiscal.

## Decisión

**Un solo código de POS (la app Vue del ADR-003), dos empaques:**

1. **PWA web** — distribución del MVP. Corre en cualquier navegador moderno;
   instalable desde Chrome/Safari. Sin tiendas de aplicaciones.
2. **APK Android vía Capacitor** — cuando llegue la integración de pagos. Capacitor
   envuelve la misma app Vue en un contenedor nativo y expone SDKs nativos mediante
   plugins puente delgados:
   - `PortalPayments` → SDK de Portal (cobro EMV/NFC integrado).
   - `SunmiPrinter` → impresora térmica integrada (resuelve la impresión en estos
     equipos sin depender de impresoras de red).
   - (Opcional) escáner de códigos integrado.

Reglas para mantener un solo cerebro:

- **Toda la lógica vive en la app Vue** (ventas, offline, outbox, sync, NCF). Los
  plugins nativos solo ejecutan una acción física y devuelven un resultado
  (`cobrar(monto) → aprobación/rechazo`, `imprimir(ticket) → ok`).
- La app detecta sus **capacidades** en runtime (`web` | `sunmi`): en web, el pago
  con tarjeta se registra manual (como en el MVP) y el ticket sale por impresora de
  red; en SUNMI, ambos van por los puentes. Misma pantalla, distinto adaptador.
- `pos_devices` registra la **plataforma** del dispositivo (`web`, `sunmi_p2`,
  `sunmi_p3_mix`) al enrolar — habilita capacidades y sirve para soporte.
- El APK se distribuye por el canal de SUNMI (instalación directa / MDM de SUNMI),
  no requiere Google Play.

## Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| App nativa Kotlin separada para SUNMI | Duplica todo el POS: dos códigos de ventas, offline y fiscal que mantener sincronizados. Riesgo enorme. |
| TWA (Trusted Web Activity) | Empaqueta la web pero **no** da acceso a SDKs nativos (Portal/impresora) — no sirve para el objetivo. |
| Reescribir el POS en Flutter/React Native | Tira la inversión de la PWA y rompe la unidad con el resto del stack. |
| Solo web + datáfono separado no integrado | Es el estado del MVP (válido), pero el cobro integrado en SUNMI es el diferenciador buscado a futuro. |

## Consecuencias

- (+) Un solo codebase de POS para web, tablets y SUNMI.
- (+) En SUNMI, impresión y cobro quedan resueltos por hardware integrado.
- (+) Refuerza al ADR-001: la elección de Vue para el POS es lo que hace esto posible.
- (−) Aparece un build Android (firma, versionado de APK, canal de distribución SUNMI)
  cuando activemos esta fase.
- (−) Plugins puente en Kotlin a mantener — se mitigan manteniéndolos mínimos
  (sin lógica de negocio).
- Fase: **el MVP sigue siendo solo PWA web**; el wrapper Capacitor se activa con la
  integración del SDK de Portal (fase 3 del doc 01).
