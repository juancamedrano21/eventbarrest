# 08 — Estructura del Proyecto y Stack

**Estado:** Construido — revisado 2026-08-02 contra el código en `eventbarrest/`.

> La app Laravel **no está en la raíz del repo**: `ProyectoRest/` contiene `docs/`
> (esto) y `eventbarrest/` (el código). El paquete se llama `boletuteam/eventbarrest`.

## Stack con versiones (lo instalado, no lo planeado)

| Capa | Tecnología | Constraint | Instalado |
|---|---|---|---|
| Lenguaje | PHP | `^8.4` | 8.4.20 |
| Framework | Laravel | `^13.8` | 13.22.0 |
| Paneles admin | Filament | `^5.7` | 5.7.3 (Livewire 4.3.3) |
| POS (PWA) | Vue 3 + Vite | `^3.5` / Vite `^8` | 3.5.40 — **JavaScript, sin TypeScript** |
| Estado POS | Pinia | `^4` | 4.0.2 |
| BD local POS | Dexie | `^4` | 4.4.4 |
| Service worker | **a mano**, sin Workbox | — | `public/pos-sw.js` |
| UI del panel Blade | Tailwind 4 + Preline 4 | `^4` | ADR-006 |
| Base de datos | MySQL (dev/prod) · SQLite (tests) | 8.x | `DB_CONNECTION=mysql`; el default de `config/database.php` es `sqlite` |
| Colas | Redis + laravel/horizon | `^5.48` | 5.48.1 · `predis ^3.5`. **Cache en `database`**, no en Redis |
| Auth API POS | laravel/sanctum | `^4.0` | 4.3.3 |
| Roles | spatie/laravel-permission | `^6` | 6.25.0 |
| Auditoría | spatie/laravel-activitylog | `^4` | 4.12.3 |
| Tests | Pest | `^4` | 4.7.5 |
| Estilo | Laravel Pint | `^1.27` | 1.29.3, en CI |
| Análisis estático | Larastan | `^3` | 3.10, **nivel 6**, en CI |
| Dev | laravel/pail, laravel/pao | — | `laravel/boost` **no se usa** |

Lo que el plan original decía y no ocurrió: **no hay TypeScript**, **no hay
`vite-plugin-pwa`/Workbox** (el service worker es nuestro, 100 líneas), **no hay
`laravel/boost`**, y **Capacitor/SUNMI sigue sin empezar** (ADR-005, fase 3).

Comandos del día a día (`composer.json`):

```bash
composer setup   # install + key + migrate + npm build
composer dev     # serve + queue:listen + pail + vite, en paralelo
composer test    # config:clear + artisan test
composer build   # vite build + filament:assets + optimize:clear
```

## Las puertas del sistema

Seis URLs, cada una con su audiencia. Quién va a cuál lo decide **una sola pieza**,
`Domains/Identity/Queries/HomeForUser` — el login, los rebotes de middleware y los
enlaces la comparten, así que no pueden contradecirse.

| URL | Qué es | Audiencia |
|---|---|---|
| `/entrar`, `/salir` | Login propio (Blade) | todos |
| `/saas-admin` | Panel Filament de plataforma | staff (`users.is_platform_admin`) |
| `/app` | Panel Filament del tenant | cuentas, en paridad con el panel nuevo |
| `/event-panel` | Panel Blade del organizador | equipo del organizador |
| `/event-vendor` | Panel Blade del comercio | personal del comercio (ADR-007) |
| `/pos`, `/event-pos` | La PWA, dos manifests | cajeros |
| `/api/pos/*` | API del POS (Sanctum) | dispositivos |

`/pos` y `/event-pos` sirven **la misma app Vue** con `modalidad` distinta: cada una
se instala con su nombre e icono, y el motor offline —la pieza más delicada— no se
mantiene por duplicado.

No hay guard `platform`: `config/auth.php` tiene **un solo guard, `web`**.

## Estructura de carpetas (real)

```
eventbarrest/
├── app/
│   ├── Console/Commands/           # ProvisionRolesCommand
│   ├── Domains/
│   │   ├── Platform/               # Tenant (base STI), FoodType, VendorType, Rules/ValidRnc
│   │   ├── Business/               # BusinessAccount, Branch
│   │   ├── EventManagement/        # OrganizerAccount, Event, EventOutlet, Vendor, EventVendor
│   │   │   ├── VendorContext.php   # la SEGUNDA frontera, junto al tenant
│   │   │   ├── Scopes/VendorScope.php
│   │   │   └── Concerns/BelongsToVendor.php
│   │   ├── Operations/             # OperatingUnit (STI) + enums
│   │   ├── Identity/               # RoleTemplate, acciones de roles, Queries/HomeForUser
│   │   ├── Tenancy/                # BelongsToTenant, TenantScope, SetTenantContext, ContextResolver
│   │   ├── Catalog/                # Category, Product (itbis_exempt), RecipeItem
│   │   ├── Inventory/              # InventoryItem, StockLevel, StockMovement, Services/StockLedger
│   │   └── Sales/                  # ← el dominio grande, ver abajo
│   ├── Filament/
│   │   ├── Admin/Resources/        # Tenants, RoleTemplates, FoodTypes, VendorTypes
│   │   └── App/Resources/          # Branches, Events, Vendors, Products, Categories, Inventory…
│   ├── Http/
│   │   ├── Controllers/{Auth,EventPanel,EventVendor,Pos,Concerns}/
│   │   └── Middleware/             # EnsurePosCapability, EnsureEventVendorUser,
│   │                               # RedirectVendorStaffToEventVendor
│   ├── Models/User.php
│   ├── Providers/Filament/{AdminPanelProvider,AppPanelProvider}.php
│   └── Support/Eloquent/{HasChildModels,IsChildModel}.php   # la mecánica STI
├── database/
│   ├── migrations/                 # 37, prefijadas por dominio
│   ├── factories/                  # Tenant, User, Branch, Event, EventOutlet, Vendor, Product…
│   └── seeders/                    # DatabaseSeeder (superadmin) + DemoSeeder
├── resources/
│   ├── css/{app,panel}.css
│   ├── js/
│   │   ├── panel.js                # autoInit de Preline: sin esto, tabs y modales no viven
│   │   └── pos/                    # ← la PWA
│   │       ├── main.js  App.vue  api.js  db.js  money.js  store.js
│   │       └── components/{LoginScreen,TillScreen,SaleScreen,SalesScreen}.vue
│   ├── panel-theme/                # plantilla Preline Pro + su layout Blade
│   └── views/
│       ├── auth/login.blade.php
│       ├── event-panel/            # dashboard, eventos, comercios, detalle de venta
│       ├── event-vendor/           # home + layout del comercio
│       ├── vendors/tabs/           # ← partials COMPARTIDOS por los dos paneles
│       └── pos.blade.php           # el cascarón de la PWA
├── public/
│   ├── pos-sw.js                   # service worker propio (cache `pos-shell-v3`)
│   ├── pos-manifest.webmanifest  event-pos-manifest.webmanifest  pos-icon.svg
│   └── panel-theme/assets/
├── routes/{web,api,console}.php
└── tests/
    ├── Feature/{Admin,Api,App,Auth,Catalog,EventManagement,EventPanel,
    │             EventVendor,Identity,Inventory,Operations,Sales}/
    ├── TenantIsolation/            # 5 suites, ver «Convenciones»
    ├── Concerns/  Fixtures/
    └── Unit/
```

Dominios del plan que **todavía no existen**: `Fiscal` (NCF/e-CF, sin empezar),
`Reporting` (las lecturas viven en `Sales/Queries` y en los controladores de
`EventPanel`) y `Sync` (la idempotencia vive en `PlaceOrder` y `PosOrderController`).

Carpetas que la convención ya usa dentro de cada dominio: `Models/`, `Actions/`,
`Enums/`, `Exceptions/`, y además `Eloquent/` (builders propios), `Queries/`
(lecturas), `Scopes/`, `Concerns/`, `Services/`, `Rules/`.

## El dominio de ventas

```
Domains/Sales/
├── Actions/    OpenCashSession · PlaceOrder · PayOrder · VoidOrder
│               RefundOrder · CloseCashSession · NextOrderNumber
├── Models/     CashSession · Order · OrderLine · Payment · Refund
├── Enums/      OrderStatus · PaymentMethod · CashSessionStatus · SalesChannel · ItbisMode
├── Queries/    NetSales · ResolveItbisMode
└── Eloquent/   SalesHistoryBuilder
```

Cuatro reglas que hay que conocer antes de tocarlo:

1. **La venta cobrada o anulada es historia.** `Order` bloquea `updating` en ambos
   estados. Corregir el pasado se hace con un `Refund` (tabla propia), nunca
   editando la orden.
2. **El número de orden es una serie por comercio.** `order_sequences`
   (`tenant_id` + `number_scope`), el número se toma **dentro** de la transacción
   de la venta —un rollback lo devuelve y la serie no deja huecos— y el canal
   presta su letra al mostrarlo: `P0041`. `NextOrderNumber` tiene dos caminos a
   propósito: en MySQL un solo `INSERT … ON DUPLICATE KEY UPDATE
   LAST_INSERT_ID()`, porque la versión ingenua con `FOR UPDATE` provoca deadlocks
   reales por gap lock en la primera venta; fuera de MySQL, `lockForUpdate()`.
3. **El ITBIS se congela por línea.** El producto marca `itbis_exempt`, y la
   modalidad —`included` (el 18 % sale de dentro del precio) o `added` (se suma al
   cobrar)— la resuelve `ResolveItbisMode` por cuenta y comercio y queda grabada en
   `orders.itbis_mode`. La propina legal siempre sobre la base sin impuesto.
   El cálculo está **duplicado a propósito** en `resources/js/pos/store.js` para
   vender sin señal, con el mismo redondeo por línea; **al sincronizar manda el
   servidor**.
4. **La comisión del organizador se congela por venta** en `orders.commission_bps`
   (null en el mundo negocio). Renegociarla no reescribe lo vendido.

## La API del POS

`routes/api.php`, todo bajo el prefijo `pos`:

```
POST /api/pos/login                       (throttle:pos-login)
GET  /api/pos/bootstrap
GET  /api/pos/catalog
POST /api/pos/sessions
POST /api/pos/sessions/{cashSession}/close
POST /api/pos/orders                      (idempotente por client_ref)
GET  /api/pos/sales
POST /api/pos/sales/{order}/refund        (permiso sales.refund)
POST /api/pos/logout
```

Stack de cada petición: `auth:sanctum → SetTenantContext → EnsurePosCapability`.
Ese último **reautoriza en cada llamada**, no solo al entrar: el rol debe seguir
vigente, la cuenta y el comercio activos, y el token llevar la habilidad `pos`.
En una cuenta de organizador el POS opera **siempre** para un comercio.

Los errores de dominio (`SalesException`) salen como **422 con `code` y mensaje en
español** (`bootstrap/app.php`); todo `api/*` responde JSON.

> **Deuda conocida:** la convención decía `/api/v1/…` desde el día 1 y hoy las
> rutas no llevan versión. Hay que resolverlo **antes** de que existan POS
> instalados en la calle, porque después ya no se puede.

## El POS offline

- `db.js`: Dexie `eventbarrest-pos`, dos tablas — `outbox` (cada venta hasta que el
  servidor la confirme) y `kv` (sesión y catálogo). Todo lo que entra se pasa por
  `JSON.parse(JSON.stringify(...))`: IndexedDB no clona proxies de Vue.
- `store.js`: una sola store de Pinia. El push es `syncOutbox()`, disparado por un
  `setInterval` de 15 s y por el evento `online` en `main.js`. No hay carpeta
  `sync/` ni motor con backoff — todavía.
- `public/pos-sw.js`: precachea el cascarón y los assets con hash leyendo
  `/build/manifest.json`. El `install` **debe completarse**: si la red cae a mitad,
  falla y el service worker anterior sigue sirviendo su shell. Los **datos nunca**
  pasan por el cache (`/api/` se excluye): viven en Dexie y en la API.

## Convenciones de código

1. **Controladores y recursos Filament delgados**: orquestan, no deciden. La lógica
   vive en `Actions` (un caso de uso = una clase invocable).
2. **Dependencias entre dominios solo vía servicios públicos o eventos.**
3. **Dinero siempre en centavos enteros** (`int`); formateo solo en presentación.
4. **Dos fronteras, no una**: `BelongsToTenant` (cuenta) y `BelongsToVendor`
   (comercio). Lo ajeno no se filtra con `if`: simplemente no existe para la query.
5. **La venta es inmutable** una vez cobrada o anulada (ver arriba).
6. **Migraciones con prefijo de dominio**:
   `2026_08_03_100001_sales_itbis_per_item_and_commission_snapshot.php`.
7. **Enums de PHP nativos** para estados.
8. **UTC en la base, `America/Santo_Domingo` para el negocio**: `config/app.php`
   mantiene `timezone => 'UTC'` y añade `business_timezone`. Los cortes de día de
   los reportes usan la segunda.

Las convenciones 3, 4 y 6 **no dependen de que alguien las recuerde en el PR**:
`tests/TenantIsolation/ModelConventionTest` y `SchemaConventionTest` las revientan
en CI. Ahí mismo viven `TenantIsolationTest`, `IdentityIsolationTest` y
`OperatingUnitInvariantsTest` (la invariante STI de los dos mundos).

## CI

`.github/workflows/ci.yml`, en cada push a `main` y cada PR: PHP 8.4 →
`pint --test` → `phpstan analyse` (nivel 6 sobre `app` y `tests/Fixtures`) →
`pest --ci`. Los tres son bloqueantes.

## Qué falta

- `Domains/Fiscal`: NCF, bloques, e-CF (ADR-004). Sin empezar.
- Colas de verdad: Horizon está instalado y no hay un solo job encolado.
- Versionar la API del POS antes de que haya dispositivos en la calle.
- Paridad del panel Blade con Filament `/app`, para poder retirar el segundo (ADR-006).
- Adaptadores SUNMI (`payments`/`printing`) y Capacitor: fase 3 (ADR-005).
