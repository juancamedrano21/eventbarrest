# 07 — Diseño de UI y Paneles

**Estado:** Propuesta — pendiente de validación

## Principio rector

Tres superficies, tres usuarios, tres contextos de uso — pero un solo sistema visual
(Tailwind + tokens compartidos) para que todo se sienta un mismo producto.

| Superficie | Usuario | Contexto | Personalidad |
|---|---|---|---|
| **POS (PWA)** | Cajero / bartender | De pie, con prisa, poca luz, táctil | Oscuro, botones grandes, cero fricción |
| **Back-office** | Dueño / gerente | Sentado, analizando, desktop | Claro, denso en datos, navegable |
| **Super admin** | Equipo de la plataforma | Herramienta interna | Funcional, tablas, sin marca de tenant |

## Decisión técnica: Filament para back-office y super admin

[Filament](https://filamentphp.com) (paneles TALL sobre Livewire) como base de los
dos paneles administrativos:

- **Es Laravel puro** — alineado con [ADR-001](adr/ADR-001-stack-laravel.md) y con el
  dominio del equipo. Nada de APIs para el back-office: Livewire directo.
- **Multi-panel nativo**: un panel `admin` (super admin) y un panel `app` (tenant),
  cada uno con su guard, su ruta y su branding.
- **Multi-tenancy integrada** compatible con nuestro modelo de `tenant_id` (ADR-002).
- Tablas con filtros/búsqueda/exportación, formularios, widgets de dashboard y
  notificaciones vienen resueltos → el MVP del back-office se acelera meses.
- **El POS no usa Filament**: es la PWA Vue custom (ADR-003). Filament es para
  administrar; el POS es para vender.

Riesgo aceptado: acoplamiento a las convenciones de Filament. Mitigación: la lógica
de negocio vive en servicios de dominio; Filament solo orquesta UI.

## POS — decisiones de experiencia

1. **Tema oscuro por defecto** (entornos nocturnos); claro opcional para cafeterías.
2. **Objetivos táctiles ≥ 56px**, sin hover, sin menús anidados en el flujo de venta.
3. **Dos modos de operación por unidad**:
   - *Modo barra*: venta rápida sin mesa — grid → cobrar (2-3 toques).
   - *Modo restaurante*: mesas/zonas, cuentas abiertas, pre-cuenta.
4. **Pantalla de cobro**: botones de denominaciones RD$ (100/200/500/1000/2000),
   cálculo de cambio en tipografía gigante, pago dividido, métodos: efectivo /
   tarjeta / transferencia.
5. **Desglose fiscal siempre visible**: subtotal, propina legal 10%, ITBIS 18%
   (ver [06 — Fiscal](06-fiscal-rd.md)); la pre-cuenta se marca "no válido como
   comprobante fiscal".
6. **Cambio de operador por PIN** sobre el mismo dispositivo (el dispositivo
   autentica con token; el humano con PIN — ambos quedan en cada venta).
7. **Acciones sensibles con PIN de supervisor**: anular pagada, descuento sobre
   límite, abrir gaveta sin venta.
8. **Estado de sincronización** persistente y discreto: verde (al día) / ámbar
   (N pendientes) / rojo (error), nunca modal, nunca bloqueante.
9. **Teclado numérico propio** en pantalla para importes — no depender del teclado
   del OS.

## Back-office — estructura

- **Navegación lateral** por módulos: Dashboard, Ventas, Inventario, Catálogo,
  Eventos, Reportes, Usuarios, Configuración.
- **Selector de contexto** en la barra superior — la pieza central de la navegación:
  cambia entre "todo el negocio", una sucursal o un punto de un evento. Refleja el
  concepto de unidad operativa del [modelo de datos](03-modelo-datos.md).
- **Dashboard** por contexto: ventas de hoy, órdenes, ticket promedio, alertas de
  stock, ventas por hora, top productos.
- **Vista de evento en vivo** (modalidad eventos): leaderboard de puntos de venta,
  semáforo de stock por punto, terminales sin sincronizar, botón de liquidación.

## Super admin — estructura

- Tenants: tabla (estado, plan, unidades, último uso), alta manual en MVP.
- Detalle de tenant: consumo, dispositivos, secuencias NCF configuradas.
- **Impersonation** ("entrar como") con registro de auditoría.
- **Salud de sincronización global**: dispositivos con > X horas sin sync → soporte
  proactivo a eventos con problemas.
- Métricas SaaS: MRR, churn, tenants activos (fase 2 con suscripciones).

## Marca blanca (white-label ligero)

Cada tenant configura **logo + color de acento**, aplicados a:

- Cabecera del POS y pantalla de bloqueo del dispositivo.
- Tickets impresos / digitales (logo, datos fiscales, RNC).
- Correos transaccionales del tenant.

El back-office mantiene la identidad de la plataforma (el white-label completo de
paneles no es MVP).

## Sistema visual

- **Tokens**: paleta neutra + color de acento; semánticos para éxito/alerta/peligro
  (stock, sync, fiscal). Tailwind como única fuente de estilos en ambos frontends.
- **Tipografía**: Inter (o system stack); números tabulares en importes y reportes.
- **Idioma**: es-DO; moneda `RD$ 1,234.00`; fechas absolutas en reportes.
- **Accesibilidad**: contraste AA mínimo en ambos temas; el POS oscuro se verifica
  con brillo bajo (uso nocturno real).

## Referencias visuales

Mockups iniciales del POS (modo barra, tema oscuro) y del dashboard del back-office
presentados en sesión de diseño del 2026-07-25 — pendiente exportarlos a
`docs/assets/` cuando se formalicen en herramienta de diseño o HTML estático.
