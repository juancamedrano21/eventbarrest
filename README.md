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

## Entorno de prueba (demo)

```bash
php artisan db:seed --class=DemoSeeder
```

Crea dos cuentas con equipo, catálogo y stock real (contraseña de todos:
`Demo-2026`): **Bar Demo** (negocio, con sucursal, Presidente y Mojito con
escandallo) y **Producciones Demo** (organizador, con el Festival del Mar 2026 y
sus tres puntos de venta). Solo funciona en local; es idempotente.

## Calidad — los tres candados del CI

```bash
vendor/bin/pint --test      # estilo
vendor/bin/phpstan analyse  # análisis estático (Larastan, nivel 6)
vendor/bin/pest             # tests (incluye la suite TenantIsolation)
```

## Los dos mundos: negocio y organizador

No hay una modalidad que se active dentro de una cuenta. Hay **dos tipos de cuenta**,
y son dos mundos cerrados que no comparten datos:

| `tenants.type` | Quién | Estructura operativa | Qué ve en `/app` |
|---|---|---|---|
| `business` | Bar, restaurante, discoteca | **Sucursales** | Sucursales |
| `organizer` | Productora de festivales | **Eventos** → sus puntos de venta | Eventos |

`tenants.type` se elige al dar de alta la cuenta y **es inmutable**: de él dependen
toda la estructura operativa y las ventas que cuelgan de ella.

**Un evento nunca comparte datos con un negocio.** Si un negocio que ya es cliente
quiere poner una barra en un festival, esa barra **se crea dentro del evento**, con su
propio catálogo, inventario y personal. Aunque lleve el mismo nombre, no comparte nada
con su negocio: son cuentas distintas y el aislamiento es el mismo que entre dos
clientes cualesquiera.

**Cada mundo tiene sus propias clases y carpetas** — la separación es estructural,
no un condicional:

| Dominio | Modelos | Qué contiene |
|---|---|---|
| `Domains/Business` | `BusinessAccount`, `Branch` | El mundo de los negocios: cuentas y sucursales |
| `Domains/EventManagement` | `OrganizerAccount`, `Event`, `EventOutlet` | El mundo de los eventos: cuentas, festivales y sus puntos de venta |
| `Domains/Operations` | `OperatingUnit` (base neutral) | Lo compartido: enums (barra/cocina, estados), builder, la vista de reportería |
| `Domains/Platform` | `Tenant` (base neutral) | La vista de plataforma: alta, plan, suspensión |

Las bases son herencia sobre una sola tabla (STI): la tabla `operating_units` es una
(así ventas y stock apuntan a una sola FK y el POS se construye una vez), pero cada
fila se hidrata como la clase de su mundo. `Branch::create()` solo sabe crear
sucursales; `EventOutlet` no existe sin evento — no hay ningún `if` que decida.

Lo que **no se puede hacer**, y está impedido estructuralmente:

- Crear un evento en una cuenta de negocio, o una sucursal suelta en una de organizador.
- Colgar un punto de venta del evento de otra cuenta.
- Mover una unidad operativa a otro evento, o convertir una sucursal en punto de venta
  (`event_id` es inmutable, también frente a updates masivos). Si dejó de operar, se
  cierra por estado.
- Cambiar el tipo de una cuenta después del alta.

**La unidad operativa** (`operating_units`) unifica ambos mundos: `event_id` nulo es una
sucursal, con `event_id` es un punto de venta. Todo lo transaccional (ventas, stock,
cajas, terminales) cuelga de ella, así que POS e inventario se construyen una sola vez.
Cada unidad declara además **qué despacha** — barra, cocina o mixta —, lo que decidirá
qué catálogo ve el POS y por qué impresora salen las comandas.

## Usuarios, roles y los dos paneles

| Panel | Quién entra | Qué exige |
|---|---|---|
| `/admin` | Staff de la plataforma | `is_platform_admin` |
| `/app` | Equipo de un negocio | pertenecer a un tenant **no suspendido** |

El staff de plataforma **no** entra en `/app`: no pertenece a ningún negocio.
Para asistir a un tenant se usará suplantación auditada, no un acceso directo.

Los roles son **por cuenta** (spatie/permission en modo teams, con `tenant_id`
como equipo): `owner`, `admin`, `event_manager`, `unit_manager`, `warehouse` y
`cashier`. Un rol concedido en una cuenta no concede nada en otra.

**Quién puede qué en una cuenta de organizador** — administrar la cuenta y
gestionar un evento son cosas distintas, y tienen permisos distintos:

| Acción | Permiso | Dueño | Admin | Gerente de eventos |
|---|---|:-:|:-:|:-:|
| Dar de alta negocios | `vendors.manage` | Sí | Sí | — |
| Crear eventos e invitar negocios | `events.manage` | Sí | Sí | Sí |
| Puntos de venta | `operating_units.manage` | Sí | Sí | Sí |
| Catálogo e insumos | `catalog.manage` | Sí | Sí | — |
| Equipo | `users.manage` | Sí | Sí | — |

La lógica: **quién administra la cuenta decide qué negocios existen; quien
gestiona un evento decide cuáles participan en él.** El gerente de eventos monta
el festival con los negocios ya dados de alta, pero no da de alta negocios nuevos.

Tras añadir un permiso al catálogo, las cuentas existentes lo reciben con
`php artisan identity:provision-roles`.

**Onboarding de un negocio nuevo:** en `/admin` se crea el negocio (sus roles se
aprovisionan solos) y, en su pestaña *Equipo*, se le da su primer **dueño**. Sin
dueño nadie puede entrar en `/app`. Después el dueño gestiona su equipo desde el
panel del negocio.

Tras añadir un permiso o rol nuevo al catálogo, los negocios ya existentes se
actualizan con:

```bash
php artisan identity:provision-roles
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
