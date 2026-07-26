# EventBarRest

Plataforma SaaS multi-tenant para gestión de bares, restaurantes, discotecas y
eventos en República Dominicana. Laravel 13 · Filament 5 · Pest 4.

> 📐 **Arquitectura y decisiones**: la documentación completa (visión, ADRs, modelo
> de datos, POS offline, fiscalidad DGII) vive en
> [BoletuTeam/DocsProyecto5](https://github.com/BoletuTeam/DocsProyecto5).

## Requisitos

- PHP 8.4 + Composer
- Node 22 + npm
- MAMP PRO (MySQL 8 en puerto `8889`, usuario `root`/`root`) — o ajusta el `.env`
- Redis, obligatorio para las colas: `QUEUE_CONNECTION=redis` y Horizon solo
  supervisa Redis. El cliente es predis, así que no hace falta la extensión nativa.

## Puesta en marcha

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
```

1. En MAMP PRO arranca MySQL 8 y crea la base de datos del proyecto.
2. Ajusta `DB_*` y `REDIS_*` en el `.env` a tu MAMP, y `APP_URL` al vhost.
3. Migra y siembra el super admin:

```bash
php artisan migrate --seed
```

El seeder crea el usuario de plataforma `admin@eventbarrest.test` con contraseña
`password` (configurable con `SUPERADMIN_EMAIL` / `SUPERADMIN_PASSWORD` en `.env`;
fuera de local el seeder **exige** una contraseña propia). Entra en `/admin`.

## Assets: servimos por vhost, no por `artisan serve`

El proyecto se sirve desde el vhost de MAMP (por ejemplo `https://boleturest.test`,
apuntando a `public/`), así que los assets van **compilados**, no por el dev server
de Vite. Tras clonar, y cada vez que cambien assets o se actualice Filament:

```bash
composer run build
```

Eso ejecuta `npm run build`, republica los assets de Filament (`filament:assets`)
y limpia las cachés. `composer run dev` (Vite con recarga en caliente) solo aplica
si trabajas contra `php artisan serve`; con vhost, usa siempre el build.

Paneles: `/admin` (super admin de la plataforma) · `/app` (negocio/tenant).
Colas: `php artisan horizon` (dashboard en `/horizon`, abierto solo en local hasta
que el dominio Identity traiga los roles de plataforma).

## Calidad — los tres candados del CI

```bash
vendor/bin/pint --test      # estilo
vendor/bin/phpstan analyse  # análisis estático (Larastan, nivel 6)
vendor/bin/pest             # tests (incluye la suite TenantIsolation)
```

## Multi-tenancy: las reglas de oro

Todo modelo de negocio **debe** usar `App\Domains\Tenancy\Concerns\BelongsToTenant`
(lo verifica un test de arquitectura, no la buena memoria de nadie).

**Lecturas y escrituras fallan cerradas por igual:**

- Sin contexto activo no se lee nada y no se escribe nada.
- `tenant_id` se rellena solo desde el contexto; nunca va en `$fillable`.
- `tenant_id` es inmutable: una fila no se mueve entre tenants.
- Cruzar de tenant es siempre explícito: `TenantContext::runAs()` para escribir,
  `Model::query()->withoutTenancy()` para leer.
- `insert()` y `upsert()` están bloqueados en modelos scopeados: saltan los eventos
  de Eloquent, y `upsert` resuelve conflictos por índice único (MySQL ignora
  `uniqueBy`), así que podría pisar la fila de otro tenant.

**Reglas de esquema** — la última línea de defensa, porque el SQL crudo se salta
todo lo anterior. En cada tabla de negocio:

- `tenant_id` NOT NULL con foreign key.
- **Todo índice único compuesto con `tenant_id`**: `unique(['tenant_id', 'codigo'])`.

La suite `tests/TenantIsolation` es el contrato: incluye los ataques reales
(escritura cruzada, mutación de `tenant_id`, upsert, mass assignment) y dos tests
de convención que revisan el esquema y los modelos. Si un cambio la rompe, el
cambio está mal. Corre en cada push por CI.

## Estructura

```
app/Domains/          ← módulos de dominio (Platform, Tenancy, Catalog, …)
app/Filament/Admin    ← recursos del panel super admin
app/Filament/App      ← recursos del panel del tenant
resources/js/pos/     ← PWA del POS (llegará en su hito)
tests/TenantIsolation ← suite de aislamiento multi-tenant
```

Convenciones completas en el documento 08 de la arquitectura.
