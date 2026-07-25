# 03 — Modelo de Datos (núcleo)

Convenciones:

- Todas las tablas de negocio llevan `tenant_id` (ver [ADR-002](adr/ADR-002-multi-tenancy.md)).
- Todo lo transaccional cuelga de una **unidad operativa** (`operating_units`), que
  puede ser una sucursal o un punto de venta de evento.
- Las órdenes y pagos usan **UUID generado en el cliente** para idempotencia en la
  sincronización offline (ver [ADR-003](adr/ADR-003-pos-offline.md)).
- Dinero en enteros (centavos) `BIGINT`; moneda DOP por defecto.

## Diagrama entidad-relación

```mermaid
erDiagram
    %% ── Nivel plataforma ──
    PLANS ||--o{ TENANTS : "suscribe"
    TENANTS ||--o{ USERS : "tiene"
    TENANTS ||--o{ OPERATING_UNITS : "tiene"
    TENANTS ||--o{ EVENTS : "organiza"

    %% ── Estructura operativa ──
    EVENTS ||--o{ OPERATING_UNITS : "contiene (puntos de venta)"
    OPERATING_UNITS ||--o{ POS_DEVICES : "registra"
    OPERATING_UNITS ||--o{ CASH_SESSIONS : "abre"
    USERS }o--o{ OPERATING_UNITS : "asignado a"

    %% ── Catálogo ──
    TENANTS ||--o{ CATEGORIES : ""
    CATEGORIES ||--o{ PRODUCTS : ""
    PRODUCTS ||--o{ RECIPE_ITEMS : "receta"
    INVENTORY_ITEMS ||--o{ RECIPE_ITEMS : "insumo"
    PRODUCTS ||--o{ UNIT_PRODUCTS : "disponible en"
    OPERATING_UNITS ||--o{ UNIT_PRODUCTS : ""

    %% ── Inventario ──
    TENANTS ||--o{ INVENTORY_ITEMS : ""
    OPERATING_UNITS ||--o{ STOCK_LEVELS : ""
    INVENTORY_ITEMS ||--o{ STOCK_LEVELS : ""
    INVENTORY_ITEMS ||--o{ STOCK_MOVEMENTS : ""
    OPERATING_UNITS ||--o{ STOCK_MOVEMENTS : ""

    %% ── Ventas ──
    OPERATING_UNITS ||--o{ ORDERS : ""
    CASH_SESSIONS ||--o{ ORDERS : ""
    ORDERS ||--o{ ORDER_ITEMS : ""
    PRODUCTS ||--o{ ORDER_ITEMS : ""
    ORDERS ||--o{ PAYMENTS : ""
    ORDERS ||--|| FISCAL_DOCUMENTS : "genera"

    %% ── Fiscal ──
    TENANTS ||--o{ NCF_SEQUENCES : ""
    NCF_SEQUENCES ||--o{ NCF_BLOCKS : "asigna bloques"
    POS_DEVICES ||--o{ NCF_BLOCKS : "recibe"
    NCF_BLOCKS ||--o{ FISCAL_DOCUMENTS : "folia"

    TENANTS {
        bigint id PK
        string name
        string rnc "RNC del negocio"
        enum status "active|suspended|trial"
        bigint plan_id FK
    }
    OPERATING_UNITS {
        bigint id PK
        bigint tenant_id FK
        bigint event_id FK "null = sucursal"
        enum type "branch|event_outlet"
        string name
        enum status "active|closed|settled"
    }
    EVENTS {
        bigint id PK
        bigint tenant_id FK
        string name
        datetime starts_at
        datetime ends_at
        enum status "draft|active|closed|settled"
    }
    PRODUCTS {
        bigint id PK
        bigint tenant_id FK
        bigint category_id FK
        string name
        bigint price_cents
        enum type "simple|recipe"
        bool track_stock
    }
    RECIPE_ITEMS {
        bigint product_id FK
        bigint inventory_item_id FK
        decimal quantity "en unidad base del insumo"
    }
    INVENTORY_ITEMS {
        bigint id PK
        bigint tenant_id FK
        string name
        string base_unit "ml|g|unidad"
        bigint cost_cents "costo promedio"
    }
    STOCK_LEVELS {
        bigint operating_unit_id FK
        bigint inventory_item_id FK
        decimal quantity
        decimal alert_threshold
    }
    STOCK_MOVEMENTS {
        bigint id PK
        bigint operating_unit_id FK
        bigint inventory_item_id FK
        enum type "purchase|sale_consumption|transfer_in|transfer_out|waste|adjustment|event_allocation|event_return"
        decimal quantity "positiva o negativa"
        string reference "orden, compra, transferencia"
    }
    ORDERS {
        uuid id PK "UUID generado en el POS"
        bigint tenant_id FK
        bigint operating_unit_id FK
        bigint cash_session_id FK
        bigint user_id FK "quien vendió"
        enum status "open|paid|void"
        bigint subtotal_cents
        bigint itbis_cents "18%"
        bigint tip_cents "propina legal 10%"
        bigint total_cents
        datetime sold_at "hora real en el POS"
        datetime synced_at "null si aún local"
    }
    ORDER_ITEMS {
        uuid id PK
        uuid order_id FK
        bigint product_id FK
        int quantity
        bigint unit_price_cents "precio al momento de la venta"
    }
    PAYMENTS {
        uuid id PK
        uuid order_id FK
        enum method "cash|card|transfer|other"
        bigint amount_cents
    }
    CASH_SESSIONS {
        bigint id PK
        bigint operating_unit_id FK
        bigint pos_device_id FK
        bigint opened_by FK
        bigint opening_cents
        bigint closing_cents
        enum status "open|closed"
    }
    POS_DEVICES {
        bigint id PK
        bigint operating_unit_id FK
        string name
        string device_token "autenticación del dispositivo"
        datetime last_sync_at
    }
    NCF_SEQUENCES {
        bigint id PK
        bigint tenant_id FK
        string ncf_type "B02|B01|E32|E31..."
        bigint next_number
        bigint end_number
        date expires_at
    }
    NCF_BLOCKS {
        bigint id PK
        bigint ncf_sequence_id FK
        bigint pos_device_id FK
        bigint from_number
        bigint to_number
        bigint used_up_to
    }
    FISCAL_DOCUMENTS {
        bigint id PK
        uuid order_id FK
        string ncf "número completo asignado"
        enum status "issued|sent|accepted|rejected|voided"
        string xml_path "e-CF firmado (S3)"
    }
```

## Decisiones de modelado clave

1. **`OPERATING_UNITS` unifica sucursal y punto de evento.** Ventas, stock, cajas y
   dispositivos siempre referencian una unidad operativa; la modalidad es un atributo,
   no una estructura distinta. `event_id NULL` ⇒ sucursal.

2. **Inventario de eventos = movimientos, no tablas nuevas.** Asignar inventario a un
   punto de evento es un `transfer_out` de la bodega principal + `event_allocation` en
   el punto. La devolución al cerrar es el movimiento inverso. La liquidación del
   evento se calcula: asignado − vendido (según recetas) − mermas − devuelto = faltante.

3. **Consumo de inventario derivado de recetas.** Al pagar una orden, cada
   `ORDER_ITEM` explota su receta y genera `STOCK_MOVEMENTS` tipo `sale_consumption`.
   Un producto `simple` con `track_stock` consume su propio ítem 1:1.

4. **Precio congelado en la venta.** `ORDER_ITEMS.unit_price_cents` copia el precio
   vigente; cambiar el catálogo nunca altera ventas históricas.

5. **UUIDs en órdenes/pagos, IDs autoincrementales en todo lo demás.** Solo lo que
   nace offline necesita UUID. El resto usa `BIGINT` por rendimiento de índices.

6. **`sold_at` ≠ `created_at`.** La hora fiscal y de reportería es la hora en que se
   vendió en el POS, no la hora en que llegó al servidor.
