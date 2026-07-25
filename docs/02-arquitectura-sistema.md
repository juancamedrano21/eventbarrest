# 02 — Arquitectura del Sistema

## Nivel 1 — Diagrama de contexto (C4)

```mermaid
flowchart TB
    superadmin["👤 Super Admin<br/>(equipo de la plataforma)"]
    duenio["👤 Dueño / Admin del negocio"]
    operador["👤 Cajero / Bartender / Mesero"]

    sistema["🏢 Plataforma SaaS<br/>Gestión de bares, restaurantes y eventos"]

    dgii["🏛️ DGII<br/>Facturación electrónica e-CF"]
    email["✉️ Servicio de Email<br/>(notificaciones, invitaciones)"]
    pagos["💳 Pasarela de pagos<br/>(suscripciones del SaaS — fase 2)"]

    superadmin -->|"Administra tenants,<br/>planes y soporte"| sistema
    duenio -->|"Configura negocio, inventario,<br/>ve reportería"| sistema
    operador -->|"Opera el POS<br/>(online u offline)"| sistema

    sistema -->|"Emite e-CF<br/>(XML firmado)"| dgii
    sistema -->|"Envía correos"| email
    sistema -->|"Cobra suscripciones"| pagos
```

## Nivel 2 — Diagrama de contenedores (C4)

```mermaid
flowchart TB
    subgraph clientes["Clientes"]
        navAdmin["🖥️ Navegador — Back-office<br/>Blade + Livewire<br/>(super admin y admin del tenant)"]
        navPOS["📱 Tablet/PC — POS PWA<br/>JS + IndexedDB + Service Worker<br/>(offline-first)"]
    end

    subgraph servidor["Servidor (monolito Laravel)"]
        app["⚙️ Aplicación Laravel<br/>Módulos: Tenancy, Catálogo, Inventario,<br/>Ventas, Eventos, Fiscal, Reportería"]
        api["🔌 API REST /api/v1<br/>(sincronización POS + futura API pública)"]
        queue["📨 Workers de cola<br/>(reportes, e-CF, emails)"]
    end

    subgraph datos["Datos"]
        mysql[("🗄️ MySQL 8<br/>BD compartida con tenant_id")]
        redis[("⚡ Redis<br/>cache, colas, sesiones")]
        s3[("📦 Almacenamiento S3<br/>logos, XML e-CF, exportes")]
    end

    dgii["🏛️ DGII"]

    navAdmin -->|HTTPS| app
    navPOS -->|"HTTPS<br/>sync por lotes"| api
    api --> app
    app --> mysql
    app --> redis
    queue --> mysql
    queue -->|"e-CF"| dgii
    app --> s3
```

## Módulos del monolito

El monolito se organiza en módulos de dominio (carpetas `app/Domains/` o paquete
`nwidart/laravel-modules` — decidir al iniciar el código). Cada módulo expone
servicios; los controladores son delgados.

| Módulo | Responsabilidad |
|---|---|
| **Platform** | Tenants, planes, suscripciones, panel super admin |
| **Identity** | Usuarios, roles, permisos, invitaciones (spatie/laravel-permission) |
| **Tenancy** | Resolución del tenant, scoping global, contexto de unidad operativa |
| **Catalog** | Productos, categorías, precios, recetas (escandallo), modificadores |
| **Inventory** | Insumos, stock por unidad, compras, transferencias, mermas, conteos |
| **Sales** | Órdenes, pagos, sesiones de caja, mesas/zonas, turnos |
| **Events** | Eventos, puntos de venta, asignación de inventario, liquidación |
| **Fiscal** | Secuencias NCF, emisión e-CF, reportes DGII (606/607 export) |
| **Reporting** | Dashboards, reportes, cierre Z, consolidados de evento |
| **Sync** | Endpoint de sincronización del POS, resolución de idempotencia |

## Reglas de dependencia entre módulos

```mermaid
flowchart LR
    Platform --> Identity
    Sales --> Catalog
    Sales --> Inventory
    Sales --> Fiscal
    Events --> Sales
    Events --> Inventory
    Reporting --> Sales
    Reporting --> Inventory
    Reporting --> Events
    Sync --> Sales
```

- **Catalog** e **Inventory** no conocen a **Sales** (dependencia en una sola dirección).
- **Fiscal** no conoce ningún dominio de negocio: recibe "documentos a foliar/emitir".
- **Reporting** solo lee (idealmente contra réplicas o tablas agregadas).

## Frontend: dos experiencias, un solo framework

| Experiencia | Tecnología | Por qué |
|---|---|---|
| Back-office (super admin, admin tenant, reportería, inventario) | **Blade + Livewire (TALL stack)** | Máxima productividad en Laravel puro; no necesita offline. |
| POS | **PWA (Vue 3 + IndexedDB + Service Worker)**, servida por el mismo Laravel | Offline-first es imposible con render de servidor. Ver [ADR-001](adr/ADR-001-stack-laravel.md) y [05 — POS Offline](05-pos-offline-sync.md). |

## Entornos

| Entorno | Propósito |
|---|---|
| `local` | Desarrollo (Laravel Sail / Herd) |
| `staging` | Pruebas de release, certificación e-CF con DGII (ambiente de pruebas) |
| `production` | Producción |
