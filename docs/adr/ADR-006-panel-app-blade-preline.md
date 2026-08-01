# ADR-006 — El panel /app migra a Blade + Alpine + Preline (estilo omnia)

**Fecha**: 2026-08-01 · **Estado**: aceptado

## Contexto

Filament nos llevó al MVP a velocidad máxima (~15 pantallas, 10 hitos), pero
su techo de dinamismo visual no alcanza el estándar de producto que el dueño
quiere para sus clientes en `/app`. Se evaluaron: Inertia+React (ReUI),
Inertia+Vue (Nuxt UI v4, shadcn-vue), tematizar Filament, y el patrón del
proyecto hermano omnia-btu (Blade + Alpine + componentes Tailwind).

## Decisión

`/app` se reconstruye con **Blade clásico + Preline UI** (Tailwind v4, capa
gratuita), el mismo patrón que omnia-btu — el que el equipo ya domina:

- Petición → Controlador (`app/Http/Controllers/Panel/`) → Vista Blade
  (`resources/views/panel/`). Sin SPA, sin API intermedia para el panel.
- Toda la lógica de negocio SIGUE en las acciones de dominio: los
  controladores solo autorizan (mismas fronteras: cuenta/comercio/permiso),
  delegan y presentan. Prohibido meter reglas de negocio en controladores.
- Interactividad: plugins JS de Preline (overlays, dropdowns) y Alpine solo
  cuando haga falta estado local. Nada de frameworks reactivos en el panel.
- El panel nuevo vive en `/panel` y CONVIVE con Filament `/app` hasta la
  paridad; entonces `/app` redirige y el panel Filament de cuentas se apaga.
- `/admin` (superadmin) SE QUEDA en Filament: herramienta interna.
- El POS sigue en Vue (ADR-005): el offline lo exige.

## Disciplina anti-omnia (lección de sus 219 controladores sin mapa)

- Un controlador por pantalla/recurso, verbos claros; máximo ~5 acciones.
- Autorización SIEMPRE vía el helper del controlador (nunca inline suelta).
- Vistas por dominio en `panel/<recurso>/`; parciales solo si se repiten.
- Cada pantalla migrada llega con sus tests HTTP (más simples que Livewire).

## Consecuencias

- Un lenguaje de servidor (PHP/Blade) para paneles; Vue queda solo en POS.
- Se pierde la maquinaria CRUD de Filament en `/app`: cada pantalla se
  escribe a mano — a cambio, transparencia total y estética Preline.
- Preline capa gratis (640+ componentes); el Pro ($249 lifetime) se decidirá
  tras evaluar el primer hito.
