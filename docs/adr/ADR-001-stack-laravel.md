# ADR-001 — Monolito Laravel full-stack; PWA solo para el POS

**Estado:** Aceptada — 2026-07-25

## Contexto

El equipo domina Laravel por encima de cualquier otro framework. La plataforma es un
SaaS multi-tenant con back-office extenso (super admin, administración del negocio,
inventario, reportería) y un punto de venta que **debe operar sin internet**
(decisión del producto: discotecas y eventos tienen conectividad poco confiable).

Se evaluó si "todo en Laravel" (incluido el frontend) es viable o tiene limitantes.

## Decisión

1. **Monolito Laravel** (última LTS disponible al iniciar) para todo el backend,
   organizado en módulos de dominio. Sin microservicios.
2. **Back-office en Laravel puro**: Blade + Livewire (TALL stack). Ninguna necesidad
   de SPA ahí.
3. **El módulo POS es una PWA** (Vue 3 + IndexedDB + Service Worker) **servida por el
   mismo Laravel**, en el mismo repositorio y deploy. Se comunica con el monolito vía
   API REST versionada (`/api/v1`).
4. MySQL 8, Redis (cache/colas/sesiones), S3 para archivos.

## Por qué el POS no puede ser Livewire

Livewire renderiza en el servidor: cada interacción es una petición HTTP. Sin red no
hay interfaz. Offline-first exige que la aplicación viva en el navegador con estado y
datos locales (Service Worker para los assets, IndexedDB para los datos). Eso es
JavaScript por definición — no es una preferencia de stack, es una restricción técnica.

El impacto se acota: **solo el POS** es PWA. Es además la pantalla que más se beneficia
de una UI instantánea (ventas rápidas en barra).

## Alternativas descartadas

| Alternativa | Por qué no |
|---|---|
| Todo Livewire, POS online-only | Viola el requisito offline; caída de red = no se vende. |
| SPA completa (Inertia/Vue en todo) | Duplica esfuerzo en el back-office sin beneficio; el equipo rinde más con Blade/Livewire. |
| App nativa/Electron para el POS | Mayor costo de desarrollo y distribución; la PWA cubre tablet/desktop e instala desde el navegador. Reevaluar solo si se necesita hardware que la web no alcance (impresoras fiscales por USB — mitigable con impresión de red). |
| Microservicios | Complejidad operativa injustificada para el tamaño del equipo; el monolito modular permite extraer servicios después si hiciera falta. |

## Consecuencias

- (+) Un solo repo, un solo deploy, un solo framework para el 90% del código.
- (+) El equipo trabaja en su stack más productivo.
- (−) El POS introduce un segundo paradigma (Vue) que hay que mantener.
- (−) Hay que diseñar y versionar una API de sincronización desde el día 1 (ADR-003).
