# 08 — Estructura del Proyecto y Stack Definitivo

**Estado:** Definido — 2026-07-25 (versiones verificadas contra Packagist/laravel.com a esta fecha)

## Stack con versiones

| Capa | Tecnología | Versión | Notas |
|---|---|---|---|
| Lenguaje | PHP | **8.4** | Filament 5 exige ≥ 8.2; usamos la estable actual |
| Framework | **Laravel** | **^13.0** (13.22 hoy) | Publicado 2026-03-17; upgrade desde 12 sin breaking changes relevantes |
| Paneles admin | **Filament** | **^5.7** | Soporta Laravel 13 (`illuminate/contracts ^11.28\|^12.0\|^13.0`); incluye Livewire |
| POS (PWA) | Vue 3 + Vite | ^3.5 | Con TypeScript |
| Estado POS | Pinia | ^2 | Stores del POS |
| BD local POS | Dexie | ^4 | Wrapper de IndexedDB |
| Service worker | vite-plugin-pwa (Workbox) | última | Precache de la app POS |
| Base de datos | MySQL | 8.x | Índices compuestos por `tenant_id` |
| Cache/colas | Redis + **laravel/horizon** | última | Colas para e-CF, reportes, emails |
| Auth API POS | **laravel/sanctum** | incluido | Tokens de dispositivo |
| Roles | **spatie/laravel-permission** | ^6 | Con teams = tenant |
| Auditoría | **spatie/laravel-activitylog** | ^4 | Log who/what/when |
| Tests | **Pest** | **^4** | Requisito de Laravel 13 |
| Estilo | Laravel Pint | incluido | Preset laravel, en CI |
| Análisis estático | Larastan | ^3 | Nivel 6 mínimo, en CI |
| Dev asistido por IA | **laravel/boost** | ^2 | MCP server oficial de Laravel para Claude Code |
| Entorno local | MAMP PRO (MySQL 8 + Redis) + `artisan serve` | — | Entorno ya instalado en la máquina del equipo |
| POS en SUNMI (fase 3) | Capacitor | ^7 | Empaqueta la misma app Vue como APK; puentes a SDK Portal e impresora SUNMI (ADR-005) |

Notas de Laravel 13 que nos tocan directamente:

- **Pest 4 y PHPUnit 12** son los requisitos de test.
- El middleware CSRF ahora es `PreventRequestForgery` (los tests del POS API deben excluir ese nombre, no el viejo).
- `cache.serializable_classes` viene endurecido por defecto — si cacheamos objetos (snapshots de reportes), hay que declararlos.
- Trae **passkeys nativos** — candidato para el login del back-office en fase 2.

## Estructura de carpetas (monolito modular)

```
proyectorest/
├── app/
│   ├── Domains/                    # ← el corazón: un folder por módulo (doc 02)
│   │   ├── Platform/               # tenants, planes, suscripciones
│   │   │   ├── Models/
│   │   │   ├── Actions/            # casos de uso: CreateTenant, SuspendTenant…
│   │   │   ├── Events/             # eventos de dominio (TenantSuspended…)
│   │   │   └── Services/
│   │   ├── Identity/               # usuarios, roles, invitaciones
│   │   ├── Tenancy/                # contexto y aislamiento
│   │   │   ├── Concerns/BelongsToTenant.php   # trait con global scope
│   │   │   ├── Middleware/SetTenantContext.php
│   │   │   └── TenantContext.php
│   │   ├── Catalog/                # productos, categorías, recetas
│   │   ├── Inventory/              # insumos, stock, movimientos
│   │   ├── Sales/                  # órdenes, pagos, cajas
│   │   ├── EventManagement/        # eventos y sus puntos de venta (ver nota ↓)
│   │   ├── Fiscal/                 # NCF, bloques, e-CF
│   │   ├── Reporting/              # consultas de lectura, cierre Z
│   │   └── Sync/                   # push/pull del POS, idempotencia
│   ├── Filament/
│   │   ├── Admin/                  # panel super admin (guard: platform)
│   │   └── App/                    # panel del tenant (tenancy de Filament)
│   ├── Http/
│   │   └── Controllers/Api/V1/     # SyncController, DeviceEnrollController
│   └── Providers/
├── database/
│   ├── migrations/                 # estándar Laravel, prefijadas por dominio en el nombre
│   ├── factories/
│   └── seeders/
├── resources/
│   ├── views/                      # mínimas: layouts, emails, tickets
│   └── js/pos/                     # ← la PWA del POS
│       ├── main.ts
│       ├── App.vue
│       ├── db/schema.ts            # Dexie: tablas locales (catalog, outbox, orders…)
│       ├── stores/                 # Pinia: session, catalog, order, outbox
│       ├── sync/                   # motor push/pull + backoff
│       ├── components/
│       ├── platform/               # adaptadores por capacidad: web | sunmi (ADR-005)
│       │   ├── payments.ts         # web: registro manual · sunmi: SDK Portal
│       │   └── printing.ts         # web: impresora de red · sunmi: térmica integrada
│       └── sw.ts                   # service worker (Workbox)
├── routes/
│   ├── web.php                     # landing + redirects a paneles
│   ├── api.php                     # /api/v1/* (Sanctum: token de dispositivo)
│   └── console.php
└── tests/
    ├── Unit/Domains/{Dominio}/     # lógica pura por dominio
    ├── Feature/                    # endpoints, paneles, flujos
    └── TenantIsolation/            # ← suite obligatoria del ADR-002
```

> **Nota de nombre:** el módulo "Events" del doc 02 se llama **`EventManagement`** en
> código, para no chocar con la convención `Events/` (eventos de dominio) dentro de
> cada módulo — evita el namespace confuso `Domains\Events\Events\…`.

## Convenciones de código

1. **Controladores y recursos Filament delgados**: orquestan, no deciden. La lógica
   vive en `Actions` (un caso de uso = una clase invocable) y `Services` del dominio.
2. **Dependencias entre dominios solo vía servicios públicos o eventos** — nunca
   tocar modelos de otro dominio directamente. Las reglas de dirección son las del
   doc 02 (`Catalog` no conoce a `Sales`, `Fiscal` no conoce a nadie).
3. **Dinero siempre en centavos enteros** (`int`), formateo solo en presentación.
4. **Todo modelo de negocio usa `BelongsToTenant`** — la excepción (modelos de
   plataforma) vive en `Domains/Platform` y se revisa en PR.
5. **Migraciones nombradas con prefijo de dominio**: `2026_07_25_000001_catalog_create_products_table.php`.
6. **API versionada desde el día 1**: `/api/v1/…`; los cambios incompatibles del
   contrato de sync abren `/api/v2`, nunca rompen la v1 (hay POS viejos offline).
7. **Enums de PHP nativos** para estados (`OrderStatus`, `MovementType`…) — los
   mismos valores que documenta el doc 03.

## Arranque del esqueleto (cuando demos luz verde)

```bash
laravel new proyectorest --pest
cd proyectorest
composer require filament/filament:^5.7 spatie/laravel-permission:^6 spatie/laravel-activitylog laravel/horizon laravel/boost
php artisan filament:install --panels
npm install vue@^3.5 pinia dexie vite-plugin-pwa typescript
```

Primer hito de código propuesto (en orden):

1. Esqueleto + CI (Pint, Larastan, Pest) verde en el primer commit.
2. `Domains/Tenancy` completo con `BelongsToTenant` + suite `TenantIsolation`.
3. `Domains/Platform`: modelo Tenant + panel Admin de Filament con CRUD de tenants.
4. `Domains/Identity`: roles del doc 04 con spatie/permission (teams).
5. De ahí en adelante, dominio por dominio según el MVP del doc 01.
