# Changelog maestro — EventBarRest (control de festivales y negocios)

> **Qué es este archivo.** La memoria completa del proyecto: cada hito que se
> construyó, **por qué** se decidió así, qué se descartó, y las **garantías** que
> dejó establecidas. Está pensado para que cualquier persona —o cualquier IA—
> entienda todo el sistema sin leer archivo por archivo ni reconstruir el árbol.
> Si vas a tocar el código, lee primero la garantía del hito que corresponde:
> son las reglas que no se pueden romper sin romper el sistema.

> **Cómo leerlo.** Ordenado por fecha. Cada hito dice **Qué**, **Por qué** y
> **Garantías**, y lleva sus hashes de commit. Los tres repos del proyecto:
> `eventbarrest` (el backend Laravel), `payrone-table-kds` (la app Android del
> KDS) y el repo raíz (documentación y ADR). Al final hay un **glosario** de los
> términos propios del proyecto y un **índice de garantías** transversales.

> **Cobertura.** 80 hitos, del 2026-07-25 al 2026-08-04. Generado del historial de git (mensajes de commit completos), los ADR y los docs.

---

## Mapa rápido por área

| Área | Hitos | Qué cubre |
|---|---|---|
| **Plataforma** | 16 | Multi-tenancy, cuentas, roles, los dos mundos (negocio/organizador), cobro con tarjeta |
| **Negocio** | 6 | Catálogo, inventario, escandallo, sucursales (mundo negocio) |
| **Eventos** | 15 | Eventos, comercios terceros, comisiones, liquidación, mercancía |
| **POS** | 13 | Punto de venta offline-first, ventas, ITBIS, propina, reembolsos |
| **KDS** | 7 | Cocina: comandas de tres estados, tiempos, comandas en vivo, batería |
| **APK (tablet)** | 6 | La app Android de la tablet de cocina (WebView) |
| **Seguridad** | 5 | Endurecimiento: alta del KDS, límites, identidad, frenos |
| **Documentación** | 6 | Documentación pública y decisiones de arquitectura (ADR) |

---

## Historia


### 2026-07-25

#### [Plataforma] Nace el esqueleto: Laravel 13, Filament y multi-tenancy fail-closed
<sub>`e9ef23b` · `36e8511`</sub>

**Qué.** Stack base: Laravel 13.22, Filament 5.7 (paneles /admin y /app), Pest 4, Pint y Larastan nivel 6 en CI, Horizon sobre Redis, Sanctum reservado para la futura API del POS. Dominio Tenancy (ADR-002): base de datos compartida con tenant_id, TenantContext como scoped singleton, TenantScope en lecturas, trait BelongsToTenant. Además, flujo de assets por build (composer run build) porque el proyecto se sirve desde el vhost de MAMP (https://boleturest.test), no con artisan serve ni el dev server de Vite.

**Por qué.** Se eligió tenancy por columna compartida y se decidió que el aislamiento fallara CERRADO desde el día uno: sin contexto no se ve nada. insert() y upsert() se bloquearon en modelos scopeados porque saltan los eventos de Eloquent y upsert resuelve conflictos por índice único (MySQL ignora uniqueBy), pudiendo pisar la fila de otro tenant. Cruzar de tenant nunca es accidental: runAs() para escribir, withoutTenancy() para leer.

**Garantías.** tenant_id es NOT NULL con FK, inmutable, nunca en fillable y se rellena siempre del contexto; toda tabla de negocio lleva índices únicos compuestos con tenant_id; todo modelo de dominio usa BelongsToTenant; lecturas y escrituras fail-closed sin contexto; insert()/upsert() prohibidos en modelos scopeados; la suite tests/TenantIsolation es el contrato del aislamiento (incluye tests de convención que lo exigen a toda tabla/modelo futuro).

#### [Plataforma] El superadmin gestiona los negocios del SaaS desde /admin
<sub>`2111d17`</sub>

**Qué.** Recurso Filament en /admin/tenants: alta, edición, búsqueda por nombre y RNC, filtro por estado, suspender/activar con confirmación. Acciones de dominio SuspendTenant/ActivateTenant, auditoría con spatie/activitylog (log 'platform'), regla ValidRnc, seeder del superadmin.

**Por qué.** No hay borrado de negocios a propósito: dar de baja es suspender, porque las ventas y comprobantes fiscales deben sobrevivir al negocio. El RNC (9 dígitos) o cédula (11) se normaliza a solo dígitos y la unicidad se valida sobre el normalizado — si no, un duplicado con guiones esquivaría la validación y reventaría contra el índice de la base. El seeder lee config y no env() en runtime porque config:cache lo anularía; exige SUPERADMIN_PASSWORD fuera de local y jamás reescribe una contraseña ya cambiada.

**Garantías.** Los tenants no se borran, se suspenden (la historia fiscal sobrevive); unicidad de RNC sobre el valor normalizado; is_platform_admin deliberadamente fuera de mass assignment; acceso a paneles vía FilamentUser::canAccessPanel.

#### [Documentación] Nace el repo de documentación: arquitectura v0.1 y los cuatro primeros ADR
<sub>`aacfb6c` · `3c0ae39` · `28b48b3`</sub>

**Qué.** Se crea ProyectoRest como repo de diseño y decisiones, separado del código: docs 01 a 07 (visión, arquitectura C4, modelo de datos, roles, POS offline, fiscalidad RD, UI), README, ADR-001 a ADR-004 y tres documentos HTML consolidados para revisión del equipo. Los .gitignore establecen que eventbarrest/ (el código) y deploy-railway/ son repos git independientes anidados dentro de esta carpeta.

**Por qué.** Diseñar y acordar antes de codificar: la v0.1 es el plano completo del sistema (luego el propio proyecto reconoce en v0.4 que describía un sistema que aún no existía). El HTML consolidado existía para que el equipo revisara el diseño sin leer markdown.

**Garantías.** El repo raíz es documentación; el código y sus hashes viven en eventbarrest/. Los PDF y HTML de julio quedan como registro histórico y no se mantienen.

#### [Plataforma] ADR-001: monolito Laravel full-stack; PWA solo para el POS
<sub>`aacfb6c`</sub>

**Qué.** Laravel 13 monolito organizado en módulos de dominio, sin microservicios; back-office en Laravel puro; el POS es una PWA Vue 3 (IndexedDB + Service Worker) servida por el mismo Laravel, mismo repo y deploy; MySQL 8 y Redis.

**Por qué.** El equipo domina Laravel por encima de todo, y el POS debe vender sin internet. Livewire renderiza en el servidor —cada interacción es una petición HTTP; sin red no hay interfaz—, así que el offline-first obliga a JavaScript en el navegador: es restricción técnica, no preferencia de stack. Descartados: todo Livewire online-only (viola el offline), SPA completa (duplica esfuerzo sin beneficio), app nativa/Electron (más costo, la PWA cubre tablet y desktop) y microservicios (complejidad injustificada para el tamaño del equipo).

**Garantías.** Solo el POS es PWA/Vue; el resto es un solo framework, un solo repo, un solo deploy. La API de sincronización se diseña desde el día 1.

#### [Plataforma] ADR-002: multi-tenancy con base de datos compartida y tenant_id
<sub>`aacfb6c`</sub>

**Qué.** Una sola base de datos; toda tabla de negocio lleva tenant_id, con global scope automático (trait BelongsToTenant), contexto resuelto en middleware (los dispositivos POS llevan el tenant en su token), índices compuestos que empiezan por tenant_id, y una suite de tests de aislamiento obligatoria en CI. Los modelos de plataforma (tenants, planes) viven fuera del scoping.

**Por qué.** Se esperan muchos tenants pequeños (bares, organizadores), no pocos gigantes. Base de datos por tenant multiplicaría migraciones y backups por N y complicaría la reportería global del superadmin; esquemas por tenant además cambiaría el motor (el equipo domina MySQL). El riesgo de que el aislamiento dependa de disciplina de código se mitiga con el trait obligatorio más los tests de CI. Ruta de escape documentada: como todo lleva tenant_id, un tenant enorme puede extraerse a una BD dedicada sin rediseño.

**Garantías.** Imposible olvidar el where tenant_id en código de negocio (scope automático); la suite de aislamiento multi-tenant corre en cada push y es un contrato, no una sugerencia.

#### [POS] ADR-003: POS offline-first con log de operaciones idempotente
<sub>`aacfb6c`</sub>

**Qué.** Outbox local en IndexedDB con UUID generado en el cliente y upsert idempotente en el servidor; catálogo como snapshot versionado; sin edición concurrente (una orden pertenece al terminal que la creó hasta sincronizar); el stock local es indicativo y se consolida en el servidor.

**Por qué.** Discotecas y eventos tienen red poco confiable: red caída no puede significar evento parado. Se descartaron CRDTs y motores de sync genéricos (PouchDB/CouchDB, ElectricSQL) por potencia innecesaria: las escrituras offline son append-only por terminal, y un outbox idempotente es más simple de razonar, depurar y auditar — importante en datos con implicación fiscal. Nota clave del README: se implementó DISTINTO al ADR — el POS envía orden por orden a POST /api/pos/orders con client_ref como clave de idempotencia y baja el catálogo completo; no hay sync/push por lotes ni delta versionado. El ADR describe el destino, no lo que corre.

**Garantías.** Reenviar es seguro: la misma referencia no duplica nada, y si se reutiliza con otro contenido el servidor lo dice en vez de fingir éxito. Un stock negativo tras consolidar genera alerta de descuadre pero nunca bloquea ventas ya cobradas. Cada hecho conserva la hora del POS y la de sincronización.

#### [POS] ADR-004: foliado NCF por bloques por terminal; e-CF en fase 2
<sub>`aacfb6c`</sub>

**Qué.** Las secuencias NCF del tenant viven en el servidor (ncf_sequences); a cada terminal se le reserva un sub-rango (ncf_blocks) que folia localmente sin red; en cada sync se registra el consumo y se reabastece; los folios de un terminal revocado se anulan, nunca se reasignan. El e-CF (Ley 32-23) usará la misma infraestructura, con emisión asíncrona por cola.

**Por qué.** Una secuencia fiscal es un contador único y dos terminales sin red no pueden repartírselo en vivo. Foliar solo en servidor o al sincronizar viola el requisito offline y deja al cliente sin comprobante válido (riesgo legal); una secuencia DGII por terminal es carga administrativa absurda. Costo asumido y documentado: los NCF no salen en orden cronológico global — consecuencia inevitable del foliado offline, con trazabilidad por terminal + timestamp. Pendiente un ADR futuro: DGII directo o proveedor autorizado.

**Garantías.** Una venta jamás espera por DGII (el XML se firma y envía en background). Folios anulados quedan documentados para auditoría. A fecha del último commit este ADR está decidido pero SIN construir: no hay secuencias ni bloques en el código, solo el permiso fiscal.manage.


### 2026-07-26

#### [Plataforma] Identity: equipo y roles por negocio, se abre el panel /app
<sub>`999864f`</sub>

**Qué.** Roles por negocio con spatie/permission en modo teams (tenant_id como equipo): owner, admin, event_manager, unit_manager, warehouse y cashier. Middleware SetTenantContext que fija el tenant del usuario para el scope y para el equipo de permisos. Onboarding: al crear un negocio se aprovisionan sus roles en la misma transacción y desde su pestaña Equipo se da el primer dueño. Pantalla de equipo en /app y comando identity:provision-roles para negocios existentes.

**Por qué.** Los permisos son globales a propósito; lo que pertenece a cada negocio son los roles y sus asignaciones — un rol concedido en un negocio no concede nada en otro. User NO usa BelongsToTenant y está documentado por qué: la autenticación ocurre antes de que exista contexto de tenant, así que un scope fail-closed impediría el propio login; su aislamiento lo dan el middleware y el Resource.

**Garantías.** Un negocio nunca se queda sin dueño; el staff de plataforma entra solo en /admin y el equipo de un negocio solo en /app; suspender un negocio deja fuera a todo su equipo; IdentityIsolationTest es el contrato (un negocio no ve, no abre ni hereda nada del equipo de otro).

#### [Plataforma] Los dos mundos: cuentas de negocio y de organizador, como estructura de clases
<sub>`f70e5d0` · `c2ca5b5`</sub>

**Qué.** tenants.type (business | organizer) elegido al alta e inmutable. Eventos (fechas, lugar, estado) solo en cuentas de organizador. OperatingUnit unifica sucursal y punto de venta: event_id nulo = sucursal, con event_id = punto de un evento; cada unidad declara qué despacha (barra, cocina, mixta). Refactor inmediato a herencia sobre una sola tabla (STI propia, sin paquetes): Domains/Business (BusinessAccount, Branch), Domains/EventManagement (OrganizerAccount, Event, EventOutlet), Domains/Operations y Platform como bases neutrales de solo lectura/reportería.

**Por qué.** Los eventos dejaron de ser una modalidad dentro de un negocio: son un tipo de cuenta propio, dos mundos cerrados que no comparten datos (si un negocio cliente quiere una barra en un festival, esa barra se crea dentro del evento del organizador). A petición del usuario, la regla de los dos mundos dejó de ser un guard condicional en código compartido y pasó a ser la estructura misma: la clase ES el mundo, las acciones quedan sin un solo condicional de mundo. La tabla sigue siendo una para que ventas y stock apunten a una sola FK y el POS se construya una vez. La revisión adversarial del refactor añadió: TenantBuilder rechaza 'type' en updates masivos, la base Tenant no es creable y type perdió su default, las factories respetan su mundo.

**Garantías.** tenants.type inmutable, también ante updates masivos; event_id de una unidad inmutable igual que tenant_id; el tipo de unidad no se elige, se deriva de si cuelga de un evento; toda cuenta nace eligiendo mundo con sus roles aprovisionados; las reglas viven en los modelos (no solo en las acciones) porque seeders, importadores y jobs futuros escriben por ahí; todo lo transaccional cuelga de OperatingUnit.

#### [Negocio] Catálogo con escandallo: costo y margen fail-closed, para ambos mundos
<sub>`8f3cbda`</sub>

**Qué.** Domains/Inventory: InventoryItem (insumo con unidad base ml/g/unidad y costo en centavos). Domains/Catalog: Category (con área de despacho barra/cocina que decidirá qué POS la muestra), Product (sencillo o con receta; el sencillo puede descontar su propio insumo 1:1) y RecipeItem (el escandallo). costCents()/marginCents()/marginPercent() en el producto; grupo Catálogo en /app con permiso catalog.manage.

**Por qué.** El hallazgo grave de la revisión adversarial: un insumo que no resolvía hacía que el costo saliera 0 y el margen 100% en verde — para un producto vendido bajo costo. Se decidió FAIL-CLOSED en el costo: desconocido es null y se muestra "—", nunca cero. Los productos no se borran, se desactivan, porque el precio congelado de las ventas históricas lo exigirá. El type del producto se hizo inmutable en el modelo (un update() lo volteaba dejando el escandallo huérfano) y category/insumo no pueden cruzar de cuenta por ningún camino.

**Garantías.** Dinero en centavos por dentro, pesos con decimales solo en pantalla; costo desconocido = null, jamás 0; productos se desactivan, nunca se borran; type de producto inmutable; una receta exige al menos un insumo; nada del catálogo cruza de cuenta.

#### [APK (tablet)] ADR-005: un solo POS, dos empaques — PWA web y wrapper Capacitor para SUNMI
<sub>`54f1c08`</sub>

**Qué.** La misma app Vue del POS se distribuye como PWA (MVP) y, cuando llegue el cobro integrado, como APK Android vía Capacitor para terminales SUNMI P2/P3 MIX, con plugins puente delgados: PortalPayments (SDK de Portal, EMV/NFC) y SunmiPrinter (impresora térmica). La app detecta capacidades en runtime (web|sunmi) y pos_devices registra la plataforma al enrolar. El APK se distribuye por canal SUNMI, sin Google Play.

**Por qué.** El riesgo a evitar era terminar con dos POS (web y Android) duplicando toda la lógica de ventas, offline y fiscal. App nativa Kotlin separada: duplica todo, riesgo enorme. TWA: empaqueta la web pero no da acceso a SDKs nativos. Flutter/React Native: tira la inversión de la PWA. La regla que lo hace sostenible: toda la lógica vive en la app Vue y los plugins nativos solo ejecutan una acción física y devuelven un resultado.

**Garantías.** Un solo cerebro de POS; los plugins puente no llevan lógica de negocio. El MVP sigue siendo solo PWA: el wrapper se activa con la integración del SDK de Portal (fase 3). A fecha del último commit solo existe la PWA.

#### [Eventos] Documentación v0.3: los eventos son un tipo de cuenta, no una modalidad
<sub>`54f1c08`</sub>

**Qué.** Reescritura de docs 01, 03, 07, 08, README y el documento de revisión del equipo: dos tipos de cuenta —negocio y organizador— como dos mundos cerrados que no comparten datos, unidos solo por el código a través del concepto de unidad operativa (sucursal o puesto de evento), del que cuelga todo lo transaccional.

**Por qué.** Un evento no es una modalidad dentro de un negocio: es un mundo propio. Consecuencia dura que quedó escrita: si un bar que ya es cliente pone una barra en un festival, esa barra es un comercio de la cuenta del organizador — aunque lleve el mismo nombre, no comparte productos, stock, ventas ni reportería con su negocio. Técnicamente son cuentas distintas.

**Garantías.** Un evento nunca comparte datos con un negocio de la plataforma. Todo lo transaccional (ventas, inventario, cajas, personal en turno) cuelga de una unidad operativa, así que POS, inventario y reportería se construyen una sola vez para los dos mundos.


### 2026-07-27

#### [Negocio] Inventario en movimiento: libro mayor inmutable y costo promedio ponderado
<sub>`65ab020`</sub>

**Qué.** StockMovement (el libro mayor), StockLevel (proyección de existencias por unidad+insumo), StockLedger (única puerta de escritura: movimiento + proyección en una transacción con locks de fila, recalculando el costo promedio ponderado en cada compra, que alimenta en vivo el escandallo), TransferStock (dos patas hermanas atómicas con referencia compartida). Grupo Inventario en /app: Existencias con acciones de compra/ajuste/merma/traslado y Movimientos de solo lectura.

**Por qué.** La revisión adversarial reprodujo DEADLOCKS REALES contra MySQL 8 que sqlite jamás mostraría: el INSERT del movimiento toma un lock S sobre el insumo por la FK y pedir el X después deadlockeaba dos compras del mismo insumo (ahora el X se toma primero); la suma para el promedio leía la snapshot vieja de REPEATABLE READ y podía commitear un costo erróneo que envenenaba las recetas (ahora lectura bloqueante). Verificado con dos procesos concurrentes: 20 compras simultáneas, 0 errores, 0 unidades perdidas.

**Garantías.** StockMovement inmutable en instancia, en masivo y en borrado — un error se corrige con un ajuste, dejando rastro; la cantidad de StockLevel solo la escribe StockLedger; el stock puede quedar negativo a propósito: una venta cobrada nunca se bloquea, el conteo físico manda; patas de traslado en orden determinista de unidad y reintento hasta 3 veces ante deadlock residual.

#### [Eventos] Decisión: los puestos de evento son vendedores terceros independientes
<sub>`60d108c`</sub>

**Qué.** Docs 01 y 08 registran el cambio de modelo: el comercio (vendor) es un negocio tercero dentro del evento. El organizador da de alta el comercio una vez, lo invita a cada evento con una comisión pactada (event_vendor.commission_bps), y los puestos —barras y cocinas— cuelgan del comercio. El comercio conserva catálogo, inventario, equipo e histórico entre ediciones.

**Por qué.** Surge de comparar con la spec de festivales del equipo (modelo marketplace estilo TOI): el modelo anterior asumía que el organizador operaba los puestos con su propio equipo. La decisión: el organizador cede el espacio y ve rendimiento; cada puesto opera su negocio sin ver a los demás. Este es el origen del segundo eje de aislamiento (vendor_id) que después atraviesa todo el código.

**Garantías.** El organizador ve el rendimiento de cada comercio, no opera por él. Dos comercios del mismo evento pueden tener cada uno su Mojito sin chocar (los índices únicos incluyen vendor_id). En cuentas de negocio vendor_id es nulo.


### 2026-07-29

#### [Documentación] Entorno demo sembrado por el dominio real y análisis de convergencia con la spec del equipo
<sub>`7989609`</sub>

**Qué.** DemoSeeder con dos cuentas completas (Bar Demo y Producciones Demo): equipo, catálogo y stock sembrado por el ledger real. Documento 09-analisis-spec-festival.md en el repo de docs (BoletuTeam/DocsProyecto5) comparando la spec de festivales del equipo con lo construido: coincidencias, gaps y ruta de convergencia.

**Por qué.** El stock se siembra con compras vivas para que el costo promedio y el margen salgan del dominio real, no de datos inventados. Sin WithoutModelEvents a propósito: las puertas legítimas del dominio SON los eventos de modelo — la columna type sin default lo demostró rechazando el insert mudo.

**Garantías.** Los seeders pasan por las mismas puertas del dominio que el código de producción; ningún seeder puede saltarse los eventos de modelo.

#### [Seguridad] La caza de los 403: el contexto de permisos se vuelve confiable
<sub>`0179446` · `17cb066` · `37c328f` · `4e62f56` · `ad060f8` · `e94f944`</sub>

**Qué.** Campaña de depuración sobre bugs reales del panel: fix en IsChildModel (getForeignKey devuelve la FK de la clase base, porque Tenant::users() sobre un OrganizerAccount derivaba organizer_account_id y reventaba la pestaña Equipo); los 403 pasan a nombrar el permiso concreto que falta y dónde pedirlo; SetTenantContext descarta las relaciones roles/permissions tras fijar el equipo; el contexto se fija con authMiddleware(isPersistent: true) cubriendo las peticiones de Livewire; TenantOwners y User::onlyOperatesThePos consultan tablas directamente; operating_units.manage se parte en branches.manage y event_outlets.manage; el cajero deja de entrar al panel de gestión; PermissionMatrixTest y LastOwnerGuardTest fijan el comportamiento.

**Por qué.** La relación roles() de spatie en modo teams se filtra por el equipo VIGENTE al momento de cargarse: si algo la consultaba antes de que el middleware fijara el equipo, Eloquent la cacheaba vacía y el usuario perdía TODOS sus permisos esa petición. Filament navega por SPA vía la ruta global de Livewire, que no heredaba los middleware del panel (menú visible, contenido 403). Ojo: la explicación definitiva la da e94f944 — la causa registrada en 4e62f56 era falsa; el mecanismo correcto es authMiddleware(isPersistent: true), que reautoriza en cada hidratación. Lo más grave: la garantía del último dueño fallaba ABIERTA desde el panel de plataforma porque dependía del equipo de permisos del momento. Encontrar el bug del cacheo fue posible gracias al 403 con nombre de permiso del commit anterior.

**Garantías.** Ninguna comprobación de seguridad depende del equipo de permisos vigente: las que protegen invariantes consultan las tablas directamente; los 403 del panel siempre nombran el permiso que falta; la matriz de permisos está fijada rol por rol y pantalla por pantalla en tests; sucursal de negocio y puesto de evento tienen permisos distintos; el sitio del cajero es el POS, no el panel.

#### [Eventos] El marketplace del organizador: comercios, participación y comisión
<sub>`f47f5d2` · `5576fba`</sub>

**Qué.** Nivel que faltaba en la cuenta de organizador: Vendor (el negocio participante, con contacto y RNC propio porque factura lo que vende), EventVendor (la participación en un evento concreto, donde vive la comisión y de donde colgará la liquidación de cada edición), y segundo nivel de aislamiento VendorContext + BelongsToVendor para catálogo, insumos y puntos de venta. Permiso nuevo vendors.manage separado de events.manage, con la matriz documentada en el README y fijada en tests.

**Por qué.** Probando el panel, el organizador no tenía forma de dar de alta los negocios que venden en sus eventos. La comisión vive en la participación porque un mismo negocio puede ir a dos festivales con condiciones distintas. La separación de permisos quedó explícita tras un bug de diseño (se reutilizaba events.manage): quien administra la cuenta decide QUÉ negocios existen; quien gestiona un evento decide CUÁLES participan — el gerente monta el festival con negocios ya dados de alta, pero no crea negocios. Los índices únicos se recompusieron con el comercio vía columna generada porque los NULL de las cuentas de negocio no restringirían en MySQL.

**Garantías.** Un punto de venta exige negocio invitado: sin participación no hay barra; dos comercios pueden tener cada uno su "Mojito" sin chocar (unicidad por comercio); cada comercio solo ve lo suyo y el organizador ve el consolidado; administrar la cuenta y gestionar un evento son permisos distintos.

#### [Documentación] Doc 09: convergencia con la spec de festivales del equipo
<sub>`4576b3d`</sub>

**Qué.** Análisis pieza a pieza de la spec de festivales de comida (referencia TOI) contra lo construido: coincidencias (multi-tenancy idéntico, estructura organizador-evento-puestos, roles 1:1, caja por turno), gaps nuestros (wallet cashless, liquidación monetaria con comisiones, KDS con estados, estación por producto, mapa de mesas, integración con boletería, el cliente final como actor) y ventajas nuestras que la spec no cubre (fiscalidad DGII —bloqueante legal en RD—, offline-first, inventario completo con escandallo y libro mayor, el mundo negocios).

**Por qué.** Conclusión ejecutiva: la spec no describe un proyecto distinto, describe un subconjunto del mundo eventos ya construido más tres piezas nuevas. Detalle premonitorio: la spec asumía WebSockets para mesas y KDS, y el análisis advierte que en un festival con red saturada ese diseño deja de vender — el ADR-009 luego resuelve el KDS por sondeo. Fija la ruta: ventas/POS, luego liquidación, luego cashless, luego mesas.

**Garantías.** El evento sigue siendo un mundo cerrado: el puesto tercero no es una cuenta nueva de plataforma. Cualquier KDS que se adopte debe degradar dignamente sin red.


### 2026-07-31

#### [Eventos] Usuarios por comercio: cada negocio del evento opera solo su mundo, y el aislamiento baja al dominio
<sub>`13de6a6` · `e47ed02`</sub>

**Qué.** users.vendor_id (FK restrict): un usuario operativo pertenece a un solo comercio; SetTenantContext fija también VendorContext desde el usuario; rol nuevo vendor_manager; el catálogo e inventario del organizador solo se escriben con comercio activo (su equipo mira el consolidado sin operar). Blindaje adversarial (24 hallazgos, 30 agentes): la autorización de modales deriva de un solo punto (get*AuthorizationResponse); las invariantes unidad↔insumo↔comercio pasan a guards de dominio con consultas sin scopes (deciden con la verdad de las filas, no con la vista del contexto); BelongsToVendor espeja a BelongsToTenant; ContextResolver único compartido por middleware y tests.

**Por qué.** El modelo que faltaba cablear: el dueño del evento crea los usuarios de cada comercio y desde entonces esos usuarios operan únicamente dentro de su comercio. La revisión descubrió que los modales de páginas de una sola pantalla no consultaban canCreate/canEdit sino las respuestas de autorización del recurso — el organizador podía escribir pese al candado — y que un usuario cuyo comercio no existía o estaba suspendido navegaba con la vista consolidada de toda la cuenta (ahora fail-closed). Los roles de cuenta no bajan al personal de comercio, ni al crear ni al cambiar de rol.

**Garantías.** El personal de comercio navega SIEMPRE con su comercio activo; vendor_id es inmutable (una fila jamás cambia de comercio); el organizador mira sin operar; traslados entre comercios rechazados; recetas y categorías no cruzan comercios; suspender un comercio corta el login de su gente; fail-closed si el comercio del usuario no existe o está suspendido; VendorPanel::writesAllowed falla cerrado sin contexto.

#### [Plataforma] Los roles pasan a plantillas en BD, operadas por el superadmin desde /admin
<sub>`6e5031e` · `93bbb8e`</sub>

**Qué.** role_templates: los roles dejan de estar grabados en código y se administran en la pantalla «Roles y permisos» de /admin — ajustar permisos, crear roles nuevos, eliminarlos — propagándose a todas las cuentas al guardar. Los 7 roles del código se siembran como plantillas de sistema. Cada plantilla declara su alcance (kind): equipo de cuenta, personal de comercio o ambos. Blindaje adversarial (29 hallazgos): identificadores de sistema reservados con siembra autocurativa, el alcance limita el contenido del rol, techo de concesión, candado del POS por capacidad, propagación transaccional por cuenta con reconciliación de roles huérfanos.

**Por qué.** El catálogo de PERMISOS sigue fijo en código a propósito: un permiso sin código que lo compruebe no protege nada; lo que se compone libremente son los roles. El hallazgo crítico: en una plataforma virgen, crear un rol llamado «Owner» capturaba el name 'owner' y ninguna cuenta podía volver a tener dueño — irreparable desde la aplicación. El techo de concesión se mide por capacidad y no por nombre para resistir roles nuevos: quien solo tiene users.manage no se asciende (ni asciende a nadie) a dueño. La siembra exige que estén TODOS los roles de sistema y completa los que falten, incluidos cases nuevos del enum en instalaciones existentes.

**Garantías.** Permisos en código, roles en BD; los names de sistema no se pueden capturar; las plantillas de sistema se afinan pero no se eliminan, y la de dueño ni se edita (es la raíz de cada cuenta); alcance e identificador inmutables tras crear; un permiso de administración de cuenta jamás entra en un rol de personal de comercio (panel y guard de modelo); nadie asigna un rol cuyos permisos superen los suyos; el acceso al POS y al panel se decide por capacidad, no por nombre de rol; la propagación es transaccional por cuenta.


### 2026-08-01

#### [POS] El dominio de ventas: caja, órdenes, cobros y consumo de inventario
<sub>`4fd2192` · `b9098e2`</sub>

**Qué.** CashSession (jornada de caja por unidad, cierre contra lo contado con esperado y diferencia con signo), Order/OrderLine (la venta, con instantáneas de nombre y precio, ITBIS 18% desglosado hacia adentro y propina legal 10% opcional), Payment (efectivo, tarjeta, transferencia), PayOrder que descuenta inventario por el libro mayor (producto simple baja su insumo, receta baja cada ingrediente por el escandallo). Blindaje adversarial (26 hallazgos): relectura con lock del estado commiteado en PayOrder/VoidOrder/CloseCashSession, consumo de stock aplanado por insumo en orden canónico de id, SalesHistoryBuilder bloquea updates y deletes masivos.

**Por qué.** La base del POS se construyó en el dominio antes que en la pantalla. Las líneas llevan instantáneas porque el catálogo cambia pero la historia no. El precio al público ya incluye el ITBIS, que se desglosa hacia adentro. La revisión corrigió que la propina legal se calculaba sobre el total con ITBIS (cobraba 11.8% efectivo): va sobre la BASE sin ITBIS. Dos terminales cobrando a la vez: una gana y la otra recibe «no está abierta», con backstop de BD (unique tenant+order). La carrera del reenvío offline devuelve la orden existente en vez de error.

**Garantías.** UNA sola caja abierta por unidad (índice único sobre columna generada); orden cobrada o anulada es historia: inmutable en orden, líneas y cobros; idempotencia por client_ref acotada a la unidad (el POS offline reenvía sin duplicar); un solo cobro por orden; el stock puede quedar negativo — un POS jamás bloquea la venta por un conteo desfasado; el efectivo jamás queda huérfano: cobrar exige la sesión abierta y una caja con órdenes abiertas no se cierra; propina legal 10% sobre la base sin ITBIS; cada línea exige producto del MISMO comercio que la unidad; Paid→Void cerrado hasta que exista el reembolso contable explícito; sin updates/deletes masivos sobre la historia de ventas.

#### [POS] API del POS: login por capacidad, contexto por token y sincronización offline idempotente
<sub>`669a039` · `5086969`</sub>

**Qué.** POST /api/pos/login (token Sanctum por dispositivo, solo para quien puede operar el POS), rutas autenticadas con SetTenantContext (contexto cuenta+comercio fijado por token, igual que en el panel), /pos/bootstrap, /pos/catalog (catálogo vendible para cachear offline), /pos/sessions y /close, /pos/orders (sincronización de ventas terminadas, idempotente por client_ref). Blindaje adversarial (24 hallazgos): rate limit 5/min por email+IP con fallo indistinguible y hash siempre, tokens con caducidad de 14 días y purga diaria, revocación inmediata al perder capacidad, EnsurePosCapability re-verifica en cada petición, contrato de sincronización honesto (client_ref_reused, 409 order_voided, 201 al crear / 200 en replay, eco de líneas y cobro para reconciliar).

**Por qué.** La puerta se decide por capacidades, como el resto de la plataforma, no por nombre de rol. Los scopes hacen que lo ajeno simplemente no exista: la sesión o unidad de otra cuenta/comercio es 404, nunca un oráculo 422. El login no da nada que enumerar, ni por mensaje ni por tiempo. El contrato de sync dice siempre la verdad: el lookup idempotente va ANTES del guard de sesión (reenviar tras cerrar caja devuelve la venta registrada), una referencia reutilizada con otra sesión u otro contenido responde client_ref_reused en vez de un éxito silencioso. Los errores del dominio responden 422 en español con código máquina estable: errores operables del POS, no fallos del servidor.

**Garantías.** Acceso al POS por capacidad, re-verificada en CADA petición; lo ajeno es 404, no un oráculo; tokens caducan (14 días) y se revocan al perder la capacidad; en cuentas de organizador el POS exige comercio activo — el equipo del organizador mira desde el panel, no vende; la idempotencia por client_ref sobrevive a reenvíos infinitos (una orden, un cobro, un descuento de stock); la PWA clasifica errores por código máquina, nunca parseando mensajes.

#### [POS] La PWA del POS: vender sin señal y sincronizar sin perder un peso
<sub>`5ac0a29` · `9a7fa5f` · `3d116f1`</sub>

**Qué.** Pantalla del cajero (ADR-005) como PWA instalable en /pos: Vue 3 + Pinia + Dexie, lista para envolver con Capacitor en los terminales SUNMI. Flujo completo: login por token de dispositivo, apertura de caja con fondo, venta por categorías con carrito, propina legal opcional, cobro en efectivo (con vuelto) / tarjeta / transferencia, y cierre contra lo contado. El catálogo se cachea en Dexie al entrar; cada venta se cobra en el dispositivo, va a una bandeja de salida local y se sincroniza al volver la señal. Service worker de cascarón (cache-first solo de assets con hash, precache desde la primera visita en v2). Los estáticos del POS (manifest, ícono) se movieron de public/pos/ a la raíz pública con prefijo pos- porque Apache veía el directorio físico y le ganaba la ruta /pos a Laravel (Forbidden). Tras una revisión adversarial con 27 hallazgos confirmados: clasificación real de errores de sync, bandeja de revisión con reintentar/descartar, reasignación automática de ventas cuya caja cerró en el servidor, borrador de venta que sobrevive la recarga, elección de caja persistida, montos con coma decimal (teclados es-DO), y en el servidor alta+cobro del sync en una sola transacción.

**Por qué.** Los festivales no tienen señal garantizada: el requisito es cobrar siempre y que el dinero cobrado al cliente jamás desaparezca de vista. Antes del blindaje, un 404 o un 422 de validación reintentaba para siempre y bloqueaba el cierre de caja diciendo «sin conexión» estando conectado; el contador compartido de client_ref podía colisionar entre pestañas o tras perder almacenamiento (pasó a UUID por venta); el doble tap en «Confirmar cobro» era un duplicado real que la idempotencia no podía unir (guard de reentrada); y un cobro rechazado en el servidor dejaba una orden abierta huérfana que bloqueaba el cierre (por eso alta+cobro van en una transacción).

**Garantías.** La API de sync es idempotente por referencia y reenviar jamás duplica. La app decide por CÓDIGO de error: solo la falta de red deja una venta pendiente; 5xx/429 es transitorio; 401/403 corta y manda al login; 4xx definitivo va a revisión humana — nunca reintento infinito. client_ref es UUID por venta, nacido en el dispositivo. El cierre de caja y el logout exigen bandeja limpia (pendientes y en revisión); cobrar durante el cierre queda bloqueado. Los DATOS nunca pasan por el service worker: viven en Dexie y en la API; el SW solo cachea assets. En el servidor, alta+cobro del sync ocurren en UNA transacción. Los estáticos del POS viven con prefijo pos- en la raíz pública: no crear un directorio físico /pos.

#### [POS] El POS entra por nombre de usuario, no por correo
<sub>`d94b285`</sub>

**Qué.** Columna users.username: corta (máx 30), minúsculas, única en toda la plataforma y opcional. El login de /api/pos pasa a username (normalizado: tolera mayúsculas y espacios accidentales del teclado). Los formularios de Equipo en /app y /admin piden el «Usuario del POS»; la pantalla de login de la PWA pide Usuario en vez de Correo. DemoSeeder con usernames de ejemplo.

**Por qué.** Un cajero en un terminal no teclea correos. Se decidió separar identidades: el correo sigue siendo la identidad de los paneles web; el username es lo que se teclea en el POS.

**Garantías.** username es único a nivel de plataforma (no por tenant) y opcional. El fallo de login sigue siendo indistinguible (anti-enumeración de usuarios). El correo no desaparece: es la identidad de los paneles.

#### [Eventos] El perfil del comercio en Filament: se entra a él, no se edita en modales
<sub>`6db7f4c`</sub>

**Qué.** Un comercio deja de ser una fila con modales: desde Negocios se entra a su perfil (/app/vendors/{id}) con todo lo relacionado — equipo del comercio (alta ya adscrita: encargado, almacén, cajero con usuario del POS), eventos en los que participa con su comisión por evento e invitación a nuevos, puestos de venta a través de todos sus eventos (crear uno pide el evento y nace adscrito), y el catálogo en solo lectura.

**Por qué.** El organizador necesitaba un lugar único para operar cada comercio sin pasar por selectores globales. La frontera decidida entonces: «el organizador mira, el comercio opera» — el catálogo se muestra en solo lectura (decisión que se revierte parcialmente ese mismo día en 9c1c2c8). Detalle técnico deliberado: los relation managers sobre páginas de vista en Filament nacen de solo lectura y hubo que declararlos operables a propósito.

**Garantías.** El perfil del comercio es la vista del ORGANIZADOR: el personal del comercio no entra a él — su mundo es su propio panel.

#### [Plataforma] Arranca el panel nuevo (ADR-006): de Filament a Blade + Preline en /panel
<sub>`9db53da` · `381d452` · `328c86d`</sub>

**Qué.** Migración de /app fuera de Filament al patrón petición → controlador → Blade con Preline UI (Tailwind v4). El panel nuevo vive en /panel y convive con Filament hasta la paridad; /admin se queda en Filament. Hito 1: esqueleto (entradas Vite panel.css/panel.js, layout con flashes) y el perfil del comercio como primera pantalla, con sus cuatro POST delegando en las acciones de dominio existentes. Hito 2: Negocios y Eventos completos — listas, altas en modal que aterrizan en el detalle recién creado, edición y suspensión (que corta el acceso de todo el personal y su POS), detalle de evento con comercios y puestos, navegación. Después el rediseño: el primer tema (oscuro, topbar) no convenció y se pasó al lenguaje de la plantilla analytics de Preline — tema claro, sidebar blanca responsive; solo cambió la piel y los 50 tests del panel pasaron sin tocarse.

**Por qué.** Decisión ADR-006 de dejar Filament para el panel del día a día en favor del patrón clásico de omnia-btu (proyecto de referencia del autor). Se migra por hitos conviviendo con el panel viejo, no en big bang. El rediseño demuestra el valor de separar markup de piel: cambiar el look completo no rompió ni un test.

**Garantías.** Disciplina del ADR-006: ningún controlador del panel autoriza por su cuenta — la autorización vive en un solo trait compartido (AuthorizesOrganizerPanel). Los POST del panel delegan siempre en las acciones de dominio; el controlador solo autoriza. Fronteras probadas en cada hito: 403 al personal de comercio, 404 cross-tenant.

#### [Plataforma] La plantilla Preline Pro comprada como shell del panel, sin viajar por git
<sub>`4a5264b` · `a1817e7` · `256efc3`</sub>

**Qué.** El dashboard de /panel sirve la Analytics Dashboard de Preline Pro idéntica a la demo, detrás de la autenticación — fase «pantalla exacta»: primero verla igual, luego sustituir los datos de ejemplo por los reales. El shell (sidebar + header) se extrae como layout compartido y las pantallas migradas viven dentro de él con la navegación mapeada a nuestras rutas. Después, el sidebar deja los items demo y dice lo que el sistema tiene (Dashboard, Negocios, Eventos, POS, Panel clásico), y los modales suben a z-80 con telón en z-70 porque el sidebar de la plantilla (z-60) se asomaba por encima de cada modal.

**Por qué.** Se compró una licencia de Preline Pro y el código licenciado NO puede viajar por git. La mecánica respeta la licencia: el HTML transformado vive en resources/panel-theme y los assets en public/panel-theme, ambos ignorados; en un entorno nuevo se restauran desde el ZIP comprado y el controlador responde un 503 instructivo si faltan. Un namespace de vistas condicional activa el layout del tema cuando está restaurado; sin él, las pantallas caen a un layout simple — así los tests y los clones frescos funcionan igual.

**Garantías.** El código licenciado de Preline Pro jamás se commitea: resources/panel-theme y public/panel-theme están en .gitignore y se restauran desde el ZIP. Las vistas del panel extienden siempre $panelLayout (variable), nunca un layout fijo — con o sin tema restaurado todo funciona. Orden de capas del panel: sidebar z-60, telón de modal z-70, modal z-80.

#### [Eventos] El perfil del comercio con pestañas: su centro de operaciones completo
<sub>`3f79719` · `5979358`</sub>

**Qué.** La vista del comercio en /panel/comercios/{id} se organiza en pestañas Preline: Resumen (eventos con comisión, puestos, catálogo), Ventas (tarjetas de hoy e histórico + últimas órdenes), Transacciones (cobros con método, recibido y vuelto), Inventario (existencias en vivo con alerta de mínimos), Usuarios (tabla estilo plantilla: avatar, usuario del POS en badge monoespaciado, rol, alta en modal) y Configuraciones (logo subible a disco público, clasificación por tipo de negocio y tipo de comida, contacto y estado). Nacen las tablas vendor_types (sembrada con Bar/Restaurante/Otros) y food_types.

**Por qué.** El perfil debía contarlo todo de un comercio en un solo lugar. La clasificación se diseñó como catálogo compartido, no como texto libre por cuenta: consistencia entre todas las cuentas de la plataforma.

**Garantías.** vendor_types y food_types son catálogos de PLATAFORMA: los administra solo el superadmin en /admin (con guard que impide borrar uno en uso) y los consumen todas las cuentas — ninguna cuenta crea los suyos.

#### [Plataforma] Migración de reparación: el esquema de ventas se verifica pieza a pieza
<sub>`08d6004` · `269de0c`</sub>

**Qué.** Una migración de reparación idempotente para las columnas de comercio en ventas: verifica cada pieza antes de tocarla (columnas vendor_id en orders/cash_sessions, intercambio del unique de client_ref) y rellena la pertenencia al comercio desde la unidad con UPDATE..JOIN. En esquemas completos no hace nada. El backfill quedó acotado a MySQL porque UPDATE..JOIN es sintaxis MySQL y los tests corren en sqlite, donde una base fresca no tiene nada que reparar.

**Por qué.** Una base restaurada a mano quedó con la migración del blindaje de ventas marcada como ejecutada pero el DDL a medias. Lección conocida del proyecto: el DDL de MySQL no es transaccional y la tabla migrations puede mentir tras un restore — por eso una reparación jamás confía en el registro de migraciones, confía en el esquema real.

**Garantías.** Las migraciones de reparación son idempotentes y defensivas: comprueban la existencia real de cada columna/índice antes de crear o modificar. El SQL específico de motor se acota con guard de driver para que la suite en sqlite no reviente.

#### [Negocio] Decisión: el dueño de la cuenta también opera dentro de los comercios
<sub>`9c1c2c8`</sub>

**Qué.** El equipo de la CUENTA con permisos de catálogo/inventario — dueño y admin — gestiona ahora el mundo del comercio desde su perfil en el panel nuevo: crear productos simples (con categoría existente o nueva), cambiar precio inline, activar/desactivar, dar de alta insumos y registrar compras por el libro mayor eligiendo el puesto.

**Por qué.** Revierte parcialmente y de forma explícita la decisión del 2026-07-31 «el organizador mira, no opera»: en la práctica el dueño de la cuenta necesita operar el catálogo e inventario de sus comercios sin cambiar de panel. El límite que se conserva: los productos con receta siguen siendo territorio del encargado del comercio.

**Garantías.** Toda operación del equipo de cuenta sobre un comercio corre COMO el comercio (VendorContext::runAs): las filas nacen con su vendor_id, lo ajeno es 404 por scope y los guards de aislamiento del dominio siguen mandando — el privilegio de cuenta no salta el aislamiento por comercio. El personal de comercio sigue sin tocar el perfil.

#### [Negocio] La pestaña Menú: catálogo con despacho real y el puente completo al inventario
<sub>`aafa8b8` · `afcd02f` · `4cc9d2b` · `06ed1a9`</sub>

**Qué.** El menú del comercio vive en su propia pestaña, agrupado por categoría con clasificación Alimentos/Bebidas. Al crear un producto se elige su naturaleza: Simple (opcionalmente vinculado a UN insumo — vende 1, descuenta 1) o Con receta, cuyo escandallo (ingredientes con cantidad y unidad, añadir/quitar) se gestiona desde el propio perfil. Cada fila muestra su vinculación real («Descuenta: Presidente (unidad)», «Receta: 2 ingrediente(s)», «Sin control de inventario»). Remate: cada ítem abre un modal premium que edita todo en un lugar — nombre, precio, categoría, estado, fiscalidad ITBIS y vinculación; el escandallo vive integrado en ese modal. En el camino se repararon parches silenciosos: modales que nunca se insertaron y ediciones por texto que no coincidieron porque el formateador había cambiado el contenido — corregidos sobre el contenido real y verificados en el HTML.

**Por qué.** La clasificación Alimentos/Bebidas no es decorativa: ES el despacho — alimentos salen de cocina, bebidas de barra — y el POS y las comandas ya la usan; ponerla en el alta de categoría cierra el circuito. Lección de proceso que quedó escrita: verificar el resultado de cada parche automatizado, no confiar en que el replace encontró su marcador.

**Garantías.** La clasificación de la categoría (Alimentos/Bebidas) determina el despacho a cocina o barra — no es un campo informativo. El insumo vinculado debe ser del MISMO comercio y las cantidades positivas (guards de dominio). El tipo de producto (simple/receta) es inmutable tras crearlo. El nombre de producto es único por comercio (misma regla que Filament). Toda la gestión corre dentro de VendorContext::runAs: lo ajeno es 404.

#### [Eventos] El dashboard deja la demo: ventas vivas y la comisión del organizador
<sub>`dffb016`</sub>

**Qué.** /panel muestra los números reales de la cuenta: ventas de hoy y de 30 días, cajas abiertas, comercios, serie diaria en ApexCharts (la librería que la plantilla ya carga), desglose de ventas por comercio y el reporte del organizador: la comisión por evento calculada sobre lo cobrado con el porcentaje pactado en cada participación — commission_bps por fin tiene consumidor. En cuentas de negocio el dashboard muestra sus KPIs sin las secciones de organizador.

**Por qué.** Fase «sustituir» del plan de la plantilla comprada: primero la pantalla exacta, después datos reales. La comisión del organizador era un dato que se guardaba desde hacía tiempo sin que nadie lo leyera; este dashboard es su primer consumidor.

**Garantías.** El personal de comercio no ve el dashboard del organizador: es redirigido a su mundo. La página demo comprada queda solo como referencia en resources/panel-theme.

#### [POS] Fiscalidad RD de verdad: ITBIS por producto y los números congelados al vender
<sub>`79052f8`</sub>

**Qué.** Cada producto declara si es exento de ITBIS (agua embotellada, alimentos no gravados) desde panel, Filament y POS. El desglose del 18 % incluido se calcula LÍNEA a LÍNEA (el redondeo del comprobante) y se congela en order_lines.itbis_cents. La venta congela también la comisión del organizador en orders.commission_bps, copiada de la participación al vender. Además: los días del negocio se cortan en America/Santo_Domingo con rangos sargables sobre paid_at e índice (tenant_id, status, paid_at); el dashboard exige el permiso reports.view_tenant; la comisión se suma entera y se divide una sola vez; la PWA espeja el cálculo por línea; y hay backfill idempotente del histórico (líneas como gravadas — así se vendió — y comisión desde el pivote vigente).

**Por qué.** Hallazgos de severidad ALTA de la revisión adversarial del dashboard: no todo el menú grava ITBIS en RD; renegociar el porcentaje de comisión o quitar un comercio del evento reescribía retroactivamente el reporte de lo ya cobrado; la venta de las 9 pm cortada en UTC caía en el día equivocado; y el SUM por fila truncaba distinto en MySQL y SQLite.

**Garantías.** Los números fiscales de una venta se CONGELAN al vender: order_lines.itbis_cents y orders.commission_bps son instantáneas — renegociar comisiones o reclasificar productos jamás reescribe ventas pasadas. El ITBIS se desglosa línea a línea, no sobre el total. La propina legal (10 %) se calcula sobre la base sin impuesto; en productos exentos su base es el precio completo. Los días del negocio se cortan en America/Santo_Domingo (UTC-4 fijo), nunca en UTC. El dashboard es fail-closed: sin reports.view_tenant, sin números. Agregaciones de dinero: sumar centavos enteros y dividir una sola vez (paridad entre motores). Ante duda (catálogo cacheado viejo, default del servidor), un producto cuenta como GRAVADO.

#### [POS] DataCloneError: los proxies reactivos de Vue no cruzan a IndexedDB
<sub>`76fc1ea`</sub>

**Qué.** Al abrir caja, la sesión se creaba bien en el servidor pero el POS reventaba al cachearla: this.session leído de Pinia es un proxy reactivo y el structured clone de IndexedDB no clona proxies. kvSet desenvuelve ahora todo con un viaje JSON de ida y vuelta; la bandeja de ventas recibe el mismo saneo; y kvGet honra su fallback también cuando la fila guardada vale null. Verificado adversarialmente con tres lentes: al recargar, bootstrap devuelve la caja ya abierta y arrive() la adopta por id; reintentar abrir choca con el único-abierta-por-unidad del servidor como error operable, sin duplicados.

**Por qué.** El mismo bug vivía latente en arrive() desde siempre, tragado por el catch genérico de «sin red» — por eso el caché nunca se refrescaba con una caja abierta. Un catch demasiado ancho había escondido un error de programación como si fuera un fallo de conectividad.

**Garantías.** Todo lo que entra a IndexedDB pasa por saneo JSON (los datos nacieron como JSON del API, el round-trip no pierde nada) — nunca guardar objetos reactivos crudos. El servidor garantiza una sola caja abierta por unidad y ese conflicto es un error operable, no un crash.

#### [Eventos] El detalle de la venta en el panel: la foto fiel y congelada de cada cobro
<sub>`ae88f21` · `69471db`</sub>

**Qué.** Cada venta abre su propia pantalla (patrón order-details del tema comprado, adaptada al dominio POS): metadatos con fecha en hora de RD, dónde se vendió (comercio, puesto, evento, caja, cajero), el pago o el motivo de anulación, el resumen fiscal congelado (subtotal, ITBIS incluido, propina legal, total, comisión pactada al vender), línea de tiempo de la orden y lo vendido línea a línea con su ITBIS instantáneo. Remates adversariales: una cortesía gravada (precio 0) mostraba «Exenta» y ahora muestra RD$ 0.00; los tests aseveran la propina EN ORDEN para evitar falsos positivos por subcadena; las ramas de orden abierta y anulada tienen cobertura; Order documenta paid_at/voided_at/void_reason/user_id en su docblock.

**Por qué.** El panel necesitaba mostrar exactamente lo que se congeló al vender, no un recálculo. El matiz de la cortesía dejó regla: la exención es una propiedad del PRODUCTO, no del precio — un precio de 0 en producto gravado no es «exento».

**Garantías.** El detalle muestra los valores fiscales congelados de la venta, jamás recalculados. La exención de ITBIS es del producto, no del precio. Fronteras probadas: venta de otro comercio 404, de otra cuenta 404, personal de comercio 403. Las fechas de cara al usuario van en hora de RD.

#### [Eventos] Modal del ítem: cierres de la revisión adversarial
<sub>`09346a6`</sub>

**Qué.** Remates del modal de producto del catálogo: reapertura del modal tras fallar la validación (old() acotado por _modal al modal que falló, HSOverlay.open con id validado por patrón), corrección del test del exento (el option del alta duplicaba el literal del badge), y guards de tenant en el modal.

**Por qué.** Un error de validación cerraba el modal y perdía lo tecleado; y el old() sin acotar habría contaminado los demás modales de la página. Los guards se bajaron al dominio (modelo Product) para que también protejan seeders e imports, no solo el formulario.

**Garantías.** Categoría e insumo de otro comercio responden 404 en el modal. Un producto con receta ignora el vínculo directo a insumo — guard de dominio en Product: el escandallo es su única forma de descontar inventario.

#### [POS] Rediseño del POS: terminal profesional touch-first, luego flat
<sub>`726e810` · `531fe20` · `b505c92` · `b76d418`</sub>

**Qué.** Rediseño visual completo del POS sin tocar el store offline: shell con píldoras de estado y bandeja del dispositivo, login y apertura de caja con montos rápidos, venta con grid de productos y ticket con stepper por línea, cobro con billetes rápidos de RD y «Exacto». Después dos correcciones de diseño: fuera todos los glows y sombras (flat, bordes de 4px, la jerarquía la dan bordes y color) y el botón de cerrar caja sube del ticket a la barra superior.

**Por qué.** El POS era un prototipo y debía verse como terminal profesional. El cierre de caja vivía debajo de Cobrar —el lugar más tocado de la pantalla— invitando al tap accidental: cerrar caja es administración, no venta, así que el pie del ticket queda con una sola acción. La revisión adversarial encontró que el precache del service worker tragaba fallos: si la red caía a mitad de instalar, se activaba un shell vacío encima de uno que funcionaba.

**Garantías.** El install del service worker FALLA si el precache no completa: jamás se activa un shell vacío sobre uno funcional (promesa offline-first entre versiones); la limpieza del activate solo toca caches pos-shell-*. toCents entiende la coma de miles de RD («1,000» no es RD$1.00). El guard anti doble cobro se mantiene. El + de una línea nace de la línea congelada: precio y exención viajan con ella.

#### [Eventos] La puerta /comercio: el panel privado del personal del comercio (ADR-007)
<sub>`6f5fa7c` · `fc0a9d4` · `acd6cbf`</sub>

**Qué.** Puerta nueva para el encargado del comercio de evento: resumen (ventas de hoy, históricas, insumos bajo mínimo), menú completo con modal y escandallo, ventas con detalle, inventario con compras. Middleware EnsureComercioUser con matriz de rebotes. Cero duplicación con /panel: traits compartidos (HandlesVendorCatalog/Inventory) y pestañas como parciales parametrizados por URL. Luego los remates adversariales (permiso reports.view_unit para ver dinero, entrada positiva por capacidades, una sola regla de estado) y el arreglo de Preline: import 'preline' no inicializa nada — panel.js llama autoInit explícito y expone HSOverlay/HSTabs en window.

**Por qué.** Cada audiencia necesita su puerta (/admin plataforma, /panel cuenta, /comercio encargado, /pos caja). La revisión adversarial cerró el fail-open: un rol vacío pasaba por «no parecer cajero» — ahora se exige al menos un permiso de gestión, y Almacén compra sin ver ventas. Los tabs estaban como HTML muerto porque el bundle del tema Pro de /panel llamaba autoInit por su cuenta y la puerta nueva no tenía quien lo hiciera. Lección Blade que costó una bisección: @php(...) inline se empareja con el primer @endphp ajeno y se traga medio archivo — siempre bloque completo.

**Garantías.** El comercio del encargado es IMPLÍCITO por su usuario, jamás elegido por URL. Matriz de rebotes fail-closed. El DINERO exige reports.view_unit también en el home. Entrada POSITIVA por capacidades, no por «no parecer cajero». Estado del comercio con UNA regla en las tres puertas: solo Suspendido corta, «En alta» opera. Las dos puertas renderizan LOS MISMOS parciales — mejorarlos mejora ambas.

#### [Plataforma] Modalidad de ITBIS: incluido en el precio o sumado al cobrar
<sub>`48059d3` · `515f10b`</sub>

**Qué.** Enum ItbisMode (incluido | se suma) que concentra toda la matemática fiscal: extrae ×18/118 o suma ×18 %, define la base de la propina y el total. La regla vive en la cuenta (default: incluido) con override por comercio (null = hereda). El POS la recibe en el catálogo y espeja el cálculo offline. Los remates adversariales taparon tres fallas de dinero: updates parciales que borraban la regla, POS cobrando con regla vieja, y ventas cobradas reescribibles por update acotado por clave.

**Por qué.** Faltaba la mitad del modelo fiscal: el sistema asumía siempre precio con el 18 % dentro, pero en RD conviven las dos formas (bares con impuesto incluido, restaurantes que lo cobran por fuera) y es regla del NEGOCIO, no del producto. El modal «Editar» sin los campos fiscales los ponía a null: editar un teléfono volvía el comercio a «incluido» en silencio — 18 % menos por cada 100 vendidos, salido de su margen. Una tablet abierta todo el turno cobraba con la modalidad de ayer dejando un faltante imputado al cajero.

**Garantías.** Nadie más multiplica impuestos: solo ItbisMode. La venta CONGELA su modalidad (como el precio, el ITBIS por línea y la comisión): cambiarla mañana no reescribe lo cobrado hoy. Lo que no viene en la petición no se toca. La apertura de caja —única llamada online garantizada del turno— revalida catálogo y regla, y el dispositivo cierra el lazo: si su total no coincide con el del servidor, la venta va a revisión con explicación. Order se defiende sola contra Order::query()->whereKey()->update(): el modelo comprueba su estado antes de dejar escribir. La regla se lee sin casts de Eloquent (valor corrupto = error de dominio, no un 500 que tumba el catálogo). El comercio se resuelve acotado por tenant.

#### [POS] Número de orden legible P0001 y el deadlock que solo MySQL enseñó
<sub>`fdc2ec9` · `cc11bd4`</sub>

**Qué.** Cada venta gana un número legible por comercio (por cuenta en el mundo negocio) con letra de canal (P punto de venta, M móvil, W web), contador con lock dentro de la transacción de la venta, índice único como backstop, e histórico renumerado. El UUID baja a dato de soporte. Segundo commit: el contador en MySQL pasa a un solo statement INSERT ... ON DUPLICATE KEY UPDATE con LAST_INSERT_ID, migración reanudable paso a paso, reintentos movidos a la transacción externa, y el POS enseña el número en la bandeja (también las últimas cobradas).

**Por qué.** El UUID mostrado como número de orden es la referencia de idempotencia del POS offline, no algo que un cliente dicta por teléfono. Serie por comercio para que el vecino no le consuma números ni le revele su volumen. La revisión adversarial reprodujo tres deadlocks contra MySQL real que sqlite jamás mostraría: SELECT ... FOR UPDATE sobre fila inexistente toma un GAP LOCK compartible y los INSERT se abrazan en la primera venta — y el gap cruzaba cuentas, un cruce de fronteras en un sistema fail-closed por tenant. Además el reintento estaba en la capa equivocada: attempts=1 fuera dejaba inertes los reintentos internos (savepoints relanzan sin reintentar).

**Garantías.** El par (cuenta, comercio, número) es único en base de datos: dos cajas simultáneas se serializan y nunca reciben el mismo. La letra es etiqueta, no serie: hay UNA secuencia por comercio, «el 41» identifica una única venta. El número se toma dentro de la transacción de la venta. El contador jamás se inventa un número ante un fallo. El catch de unique distingue reenvío offline de choque de número. Los reintentos de transacción viven solo en la capa externa, la única donde Laravel reintenta de verdad.

#### [Plataforma] ADR-006: los paneles de cuenta migran de Filament a Blade + Alpine + Preline
<sub>`b563048`</sub>

**Qué.** El panel /app se reconstruye con Blade clásico + Preline UI (Tailwind 4, capa gratuita), patrón del proyecto hermano omnia-btu: petición → controlador → vista Blade, sin SPA ni API intermedia. /admin (superadmin) se queda en Filament como herramienta interna; el POS sigue en Vue porque el offline lo exige. Incluye la disciplina anti-omnia, lección de sus 219 controladores sin mapa: un controlador por pantalla con máximo ~5 acciones, autorización siempre vía helper, vistas por dominio y tests HTTP por pantalla migrada.

**Por qué.** Filament llevó al MVP a velocidad máxima (~15 pantallas, 10 hitos), pero su techo de dinamismo visual no alcanza el estándar de producto que el dueño quiere para sus clientes. Se evaluaron y descartaron Inertia+React, Inertia+Vue y tematizar Filament: se eligió el patrón que el equipo ya domina. Se pierde la maquinaria CRUD de Filament a cambio de transparencia total.

**Garantías.** Toda la lógica de negocio sigue en las acciones de dominio: los controladores solo autorizan, delegan y presentan — prohibido meter reglas de negocio en controladores. Nada de frameworks reactivos en los paneles (Alpine solo para estado local).

#### [Documentación] Doc 06 al día: el ITBIS por producto y la propina legal ya viven en el código
<sub>`c235f33`</sub>

**Qué.** El documento fiscal pasa de plan a estado real: el desglose de ITBIS por línea con productos exentos y la propina legal calculada sobre la base ya están en el dominio de ventas. Se anota qué es constante hoy (18 % de ITBIS, 10 % de propina) y qué sigue pendiente de la fase fiscal: NCF, reportes 606/607 y e-CF.

**Por qué.** El doc 06 es el que el README ordena leer antes de tocar dinero; tenía que reflejar el código y no el plan. La modalidad del ITBIS quedó documentada: incluido en el precio (se extrae por 18/118 y el total no crece) o por fuera (se suma al cobrar), declarada por el comercio y, en su defecto, por su cuenta — un comercio de evento es un negocio tercero y puede cobrarlo por fuera aunque el organizador lo incluya.

**Garantías.** El desglose de ITBIS se congela línea a línea al vender. La propina legal (art. 228) viaja dentro de total_cents y no es venta del negocio. Las tasas y calendarios deben validarse con un contador dominicano antes de implementar la fase fiscal.


### 2026-08-02

#### [POS] Reembolsos como hecho nuevo y ventas del turno en el POS
<sub>`0d17e15` · `f9cdc18`</sub>

**Qué.** Modelo Refund: referencia la venta con monto, método, motivo obligatorio y quién autorizó; permiso propio (Encargado y Gerente de unidad sí, cajero no); total o parcial. Listado de ventas del turno en el POS (del servidor, con búsqueda por número y estados con color). Segundo commit: lo devuelto se resta en TODOS los reportes (dashboard, KPIs, serie, por comercio, por evento con subconsulta, perfil, resumen de /comercio, comisión con el bps congelado) más tres guards de caja.

**Por qué.** La venta es el registro inmutable de lo que ocurrió; el dinero que vuelve es un HECHO NUEVO — como lo pide la contabilidad y como lo exigirá la DGII (futura nota de crédito B04). La revisión midió lo peor: una venta de RD$1,000 reembolsada entera seguía como RD$1,000 de ventas, y el organizador facturaba RD$100 de comisión por dinero devuelto — cobro indebido creciente. Por evento se agrega con subconsulta y no join: dos reembolsos habrían duplicado el bruto de su venta. El guard de unidad comparaba solo vendor_id, que en el mundo negocio es null en ambos lados y no protegía nada.

**Garantías.** La venta no se toca: el reembolso es un asiento nuevo, nunca más de lo pendiente por devolver, y un reembolso escrito tampoco se edita (corregir es otro asiento; Refund implementa su guard de fila como Order). Se devuelve POR DONDE SE COBRÓ y sale de la caja abierta de quien devuelve, en la MISMA unidad de la venta. El inventario NO se repone (reponer será ajuste explícito, no efecto secundario del dinero). El reembolso EXIGE conexión. El reembolso resta el día en que salió de la gaveta, que es como cuadra el arqueo. El método del endpoint se valida como enum (422, no 500).

#### [Eventos] El resumen del comercio con números reales y pestañas que no se pierden
<sub>`0f0bc43` · `287ea0a`</sub>

**Qué.** Resumen de 30 días: ventas netas de reembolsos, transacciones y ticket promedio, ITBIS del período, propinas, gráfica diaria de 14 días cortada en día de RD, top 5 productos leído de las líneas congeladas, desglose de métodos de pago. Auditoría del catálogo: cada cambio de precio/nombre/estado/fiscalidad registra quién, cuándo y desde qué valor, contado en cristiano en el resumen. Guard: producto con ventas no se borra. Y las pestañas: se recuerdan por pantalla en el navegador (el back() pierde el hash), rediseñadas como pastillas con contadores, navegación única compartida por /panel y /comercio.

**Por qué.** El resumen estaba vacío y guardar cualquier formulario devolvía al usuario al Resumen perdiendo su pestaña. Un log que no se lee no es una auditoría, por eso se narra («Ana actualizó Taco · precio: 200.00 → 275.00»). El fragmento de URL no viaja al servidor, así que la memoria de pestaña es del navegador, no del redirect. La trampa de @php(...) inline en Blade mordió por segunda vez y quedó documentada donde muerde.

**Garantías.** Un producto vendido NO se borra — guard en el modelo, no en el formulario: se desactiva; sin ventas sí se borra y el mensaje explica la diferencia. Las cifras diarias cortan el día en hora de RD. Los más vendidos se leen de las líneas congeladas, no del catálogo actual. @php inline queda prohibido en la práctica: siempre bloque @php/@endphp.

#### [Seguridad] Login propio /entrar: la entrada única de la plataforma
<sub>`1dec80f` · `93fa51d`</sub>

**Qué.** Pantalla de login propia que acepta correo O usuario, con la decisión de destino concentrada en HomeForUser: staff a /admin, equipo de cuenta a /panel, gestión de comercio a /comercio, quien solo opera caja a /pos. Anti-enumeración, throttling de 5 intentos por identidad+origen, sesión regenerada al entrar y salir, suspensión corta en la puerta. Y la raíz / deja de ser la bienvenida de Laravel: con sesión desvía por la misma pieza, sin sesión a /entrar.

**Por qué.** La única entrada era /app/login (Filament), así que el panel viejo no se podía apagar aunque sus pantallas estuvieran migradas. Quién decide el destino vive en UNA pieza para que login, middlewares y enlaces no puedan contradecirse. El personal de comercio entra con el nombre corto que ya usa en el POS. El día que exista sitio de marketing, ocupa la raíz y el desvío se vuelve un enlace «Entrar».

**Garantías.** HomeForUser es LA única pieza que decide el destino de cada usuario — nada más puede rutear audiencias. Mismo mensaje para cuenta inexistente y clave errada (anti-enumeración). Máximo 5 intentos por identidad+origen. Sesión regenerada en login y logout. La suspensión de cuenta cierra también esta puerta sin dejar sesión a medias.

#### [Plataforma] Una puerta por modalidad: el gran renombrado (ADR-007)
<sub>`5673800` · `2205f2e`</sub>

**Qué.** Renombrado completo (URLs, nombres de ruta, namespaces EventPanel/EventVendor, carpetas de vistas y tests, middlewares, destino del login): /saas-admin (antes /admin), /event-panel (antes /panel), /event-vendor (antes /comercio), /event-pos (caja de evento, nueva), /pos (caja del negocio), /business (por construir). Cada POS con su URL, manifiesto e identidad de instalación —dos apps en el móvil— sobre UN solo motor offline. Más un commit de Pint para que /business no naciera con el CI en rojo por deuda ajena.

**Por qué.** /panel exigía cuenta de organizador en todas sus pantallas y solo el dashboard se adaptaba con ocho condicionales por mundo — la costura que se pidió eliminar en julio. Separar por modalidad es coherente con el dominio, ya partido en Business y EventManagement. /vendor se descartó para el mundo negocio porque en el código Vendor ES el comercio de evento: la colisión costaba más que renombrar. El login rechaza al cajero del mundo equivocado con un mensaje que dice a dónde ir, no «credenciales incorrectas».

**Garantías.** El motor offline del POS es UNO: outbox, sincronización y arqueos no se duplican jamás — es lo más delicado del sistema y en Android serían dos apps para el mismo trabajo. Si algún día divergen, el corte va en la pantalla, no en el motor.

#### [Negocio] /business: la casa del bar independiente (ADR-008)
<sub>`7b813bc`</sub>

**Qué.** Puerta completa del mundo negocio: resumen, menú, inventario, ventas, caja, sucursales, equipo y ajustes. Tres pantallas que no existían en ningún panel: histórico de arqueos (esperado y diferencia por turno), ajustes fiscales (casa del permiso fiscal.manage, que nadie comprobaba), y ajuste de conteo/merma/traslado/umbral con el libro mayor de stock. SalesSummary separa la propina legal de las ventas con prorrateo de reembolsos. Dos arreglos al mundo eventos: el cajero de comercio va a /event-pos, y las capacidades de gestión pasan al enum junto a accountOnly() y posOnly().

**Por qué.** El mundo negocio tenía dominio (BusinessAccount, Branch) pero ninguna puerta: su dueño aterrizaba en un panel de organizador y su cajero quedaba en un callejón sin salida. tenants.itbis_mode solo se editaba desde /saas-admin, así que todo bar facturaba con el default. La propina del 10 % viaja dentro de total_cents y los reportes la contaban como venta del negocio, pero en RD ese dinero es del personal; refunds guarda importe plano, así que sin prorrateo una venta con propina reembolsada entera daba ventas negativas.

**Garantías.** La propina legal NO es venta: SalesSummary la separa y prorratea los reembolsos (devolver media venta devuelve media propina) — jamás sum('total_cents') directo. El catálogo es de la cuenta entera, no por sucursal (ADR-008: no hay columna que ate producto a local). No existe vínculo usuario↔sucursal: cualquier gerente ve todas. Las capacidades de gestión viven en el enum, en un solo sitio.

#### [Eventos] /event-panel completo y el apagado de /app
<sub>`0ddbc7b` · `d77eb10`</sub>

**Qué.** Cinco capacidades que solo vivían en el Filament viejo llegan a /event-panel: editar evento y su estado (cerrar y liquidar), editar puesto, renegociar comisión de una participación, sacar a un comercio de un evento, y cambiar el rol del equipo de un comercio. Con eso se apaga /app: se borra app/Filament/App entero (9 recursos), su PanelProvider y el middleware de convivencia. Antes de borrar se rescataron los tests que protegían reglas de negocio: la matriz observada de rol × pantalla reescrita contra /business y /event-panel, el aislamiento de identidad por HTTP, y las reglas de equipo.

**Por qué.** Retirar /app estaba bloqueado por dos paneles: sin estas pantallas, un festival no se podía cerrar ni liquidar desde ninguna parte del sistema. Las reglas del dominio se cuentan al organizador en su panel en vez de reventar en 500: una caja abierta es algo que él puede resolver. Un cambio que los tests viejos consagraban al revés: quien solo puede operar caja ya no recibe un 403 seco — un callejón sin salida no es una medida de seguridad. Ojo: esto NO desacopla el dominio de Filament — el paquete queda por /saas-admin y 19 enums implementan HasLabel/HasColor.

**Garantías.** Cerrar un evento exige que no queden cajas abiertas; liquidar pide events.settle aparte (es el corte financiero). El evento y el comercio de un puesto no se tocan: moverlo reescribiría de quién son las ventas ya salidas. Renegociar comisión no cambia lo cobrado: cada orden lleva la suya congelada. Sacar a un comercio CIERRA sus puestos, no los borra (sus ventas los referencian), y con caja abierta no se saca a nadie.

#### [Seguridad] Revisión adversarial del apagado: 25 hallazgos confirmados
<sub>`4bcb9bc`</sub>

**Qué.** Cinco lentes en paralelo sobre /business, /event-panel y el apagado, con un verificador por hallazgo (25 confirmados, 16 refutados). Dinero: el filtro de fechas de /business/ventas cortaba en UTC (la franja de más venta se atribuía al día siguiente) y Carbon mutable inflaba el rango en cada reenvío; el ITBIS de 30 días se etiqueta «facturado». Seguridad: el desplegable de rol podía ascender a Administrador en silencio; renegociar comisión daba de alta comercios no invitados; deshacer una liquidación no pedía events.settle. Se restauraron capacidades perdidas al apagar /app (ajustes de inventario del mundo eventos, validación de unicidad en altas, pantallas de equipo) y varios 500 pasaron a mensajes en el panel.

**Por qué.** Un usuario con un rol heredado que ya no se ofrece dejaba el desplegable sin selección: el navegador enviaba la primera opción —Administrador, con TODOS los permisos— y guardar su correo lo ascendía en silencio. El badge «Bajo mínimo» era decorado porque nada podía fijar el umbral. Y HomeForUser mandaba a /business a quien el guard iba a rechazar con 403 — ahora acaba en la entrada con una explicación y el login corta el bucle.

**Garantías.** Todo filtro de fechas corta el día en hora de RD, con límites calculados sin mutar el objeto. Cada desplegable de rol incluye el rol vigente de SU usuario y la validación lo acepta. Renegociar una comisión exige que la participación exista. Entrar Y salir del estado liquidado exigen events.settle. El ITBIS reportado es «facturado» (bruto) hasta que existan notas de crédito — decirlo vale más que disimularlo. Los errores que el usuario puede resolver se cuentan en su panel, no como 500.

#### [POS] El POS con fotos de producto, dos temas y el layout de la referencia
<sub>`879437c`</sub>

**Qué.** Fotos de producto (columna nueva, subida desde el modal en las dos puertas de catálogo, borrado del archivo anterior al reemplazar; sin foto, bloque de color derivado del nombre). Tema claro por defecto y oscuro a un toque, guardado en localStorage. Pestañas de categoría con contador que respeta la búsqueda, tarjetas con foto grande y badge de disponibilidad, botón «Añadir más (n)», ticket con miniatura y botón de quitar la línea entera, reloj por minuto. El catálogo manda también los inactivos y el POS los pinta en gris intocables.

**Por qué.** Trabajo sobre una captura de referencia del dueño, con tres decisiones suyas y una contra la referencia a propósito: las esquinas se quedan en 4px porque el radio no hace moderno a un POS y la pantalla la mira alguien de pie con prisa. El tema va en localStorage y no en el outbox porque debe leerse antes del primer pintado y es preferencia de la tableta, no de la cuenta. Esconder los productos inactivos dejaba al cajero preguntándose si el plato existe; decirle «agotado» le deja responder al cliente. En una orden de ocho cervezas, tocar ocho veces el menos es un castigo.

**Garantías.** El catálogo manda TAMBIÉN los inactivos y el POS los muestra como agotados, nunca los oculta. El tema es preferencia del dispositivo (localStorage), no de la cuenta. Al reemplazar una foto se borra el archivo anterior. El mismo plato sin foto tiene siempre el mismo color (derivado del nombre).

#### [POS] El cobro se cierra completo: confirmación en pantalla, impresión y nombre del cliente
<sub>`84c4474` · `8fe8f44`</sub>

**Qué.** Hoja de confirmación al cobrar con total, vuelto, nombre y número de orden; impresión desde el navegador (sin drivers) en dos formatos: la comanda separada por destino cocina/barra y sin precios, y el recibo con desglose, pago, vuelto y advertencia de no-fiscal; nombre del cliente opcional en el modal de cobro, congelado con la venta y en grande en la comanda; reimpresión desde el listado del turno, que ahora recibe las líneas congeladas de cada venta; el ticket legible se guarda con la venta local. El modal de venta cobrada pasó a contar la venta entera: líneas con cantidades y precios, subtotal, ITBIS con su modalidad, propina, método de pago, recibido y vuelto, quién cobró, hora, sucursal, y si la venta ya está registrada en el servidor o vive todavía en la tableta esperando señal.

**Por qué.** El modal se cerraba y el cajero quedaba sin saber si la venta pasó. El número de orden lo asigna el SERVIDOR: sin señal todavía no existe, y la hoja lo dice («se asigna al sincronizar») en vez de inventarse uno, rellenándolo al sincronizar. La comanda no lleva precios porque quien cocina no los necesita y solo añaden ruido. El recibo advierte que no es comprobante fiscal válido para crédito — lo será cuando exista el NCF. El nombre es opcional porque en una barra llena pedirlo siempre es fricción. Las líneas congeladas van en el listado porque pedirlas en una segunda petición castigaría al cajero que reimprime en pleno servicio. La distinción sincronizada/pendiente importa cuando el cliente pregunta y cuando el encargado cuadra el turno.

**Garantías.** El número de orden solo lo asigna el servidor; el aparato jamás inventa uno. La comanda impresa nunca lleva precios. El recibo declara explícitamente que no es comprobante fiscal (hasta que exista NCF). El ticket legible se congela con la venta local: reimprimir no depende del catálogo (un producto puede desaparecer de la carta) ni de la red. El nombre del cliente se congela con la venta como todo lo demás.

#### [Eventos] La liquidación del evento: el estado de cuenta que faltaba
<sub>`3107854`</sub>

**Qué.** Pantalla de liquidación por evento: borrador vivo mientras el festival ocurre y cifras GUARDADAS al liquidar. El organizador elige entre tres reglas de base de comisión (el default sigue siendo la histórica, sobre total_cents), y la regla elegida se congela en cada venta junto al porcentaje. Se anota qué comercio ya pagó su comisión, cuándo y con qué nota. Liquidar exige cero cajas y cero órdenes abiertas, y tras liquidar el evento no admite reembolsos. Se eliminó la opción de marcar «liquidado» a mano en el desplegable de estado, y se arregló el guard de reembolsos que comparaba el estado del evento (enum de Eloquent) con una cadena — era siempre falso y no protegía nada; lo encontró el test que lo perseguía.

**Por qué.** Era el agujero más grande del mundo eventos y la razón de ser del modelo de negocio: la comisión se congelaba por orden hace tiempo pero nadie podía ver cuánto le tocaba a quién — se calculaba a mano. Además la comisión se cobraba sobre orders.total_cents, es decir sobre el ITBIS que el comercio le debe a la DGII y sobre la propina de sus meseros: en una venta de RD$1,000 con impuesto incluido, RD$108.48 en vez de RD$84.75 — un 28% de más sobre dinero que no es del comercio. El default no cambió para no moverle el dinero a nadie en silencio: quien quiera la regla recomendada la marca con los tres números delante. Las cifras se guardan al liquidar porque recalcular al abrir la pantalla haría que un reembolso tardío moviera una cuenta ya pagada de mano. Una caja abierta es dinero que todavía puede entrar.

**Garantías.** La regla de comisión y el porcentaje se congelan por venta: cambiar el ajuste a mitad de festival rige hacia delante y JAMÁS reescribe lo ya cobrado — la liquidación suma cada tramo con lo pactado en su momento. Las cifras de una liquidación guardada no se recalculan. Liquidar exige que no quede nada abierto. Evento liquidado no admite reembolsos: es punto final. Liquidar solo ocurre donde se calcula, nunca marcándolo a mano.

#### [Eventos] La mercancía del evento: entregar, devolver y ver qué falta
<sub>`58048b8`</sub>

**Qué.** Cuadre físico de la mercancía de cada puesto: entregado + comprado + recibido + ajustado − vendido − mermado − devuelto − enviado = lo que falta. Dos movimientos nuevos que el enum ya prometía —event_allocation y event_return— con contraparte opcional en la bodega del comercio. Lo vendido no se estima: sale de los movimientos que las recetas escriben al cobrar (un mojito descuenta su ron solo). El selector de insumos se filtra por el comercio dueño del puesto y las escrituras entran en el contexto de ese comercio. También: el comando panel:fix-theme-links versiona la corrección del enlace muerto «Panel clásico» en el tema con licencia.

**Por qué.** La liquidación cuadra el dinero; esto cuadra lo que bajó del camión, que hasta ahora se llevaba en una libreta. Un ajuste de conteo entra sumado con su signo porque ya explicó su parte del hueco y cobrarlo dos veces sería acusar a alguien de más. El organizador escribe en el inventario de otro, así que pedir un insumo ajeno es un 404, no un regalo. El tema Preline Pro con licencia vive fuera de git: una edición a mano se perdería al restaurar el ZIP, por eso la corrección viaja como comando versionado.

**Garantías.** Lo vendido sale siempre de los movimientos de recetas, nunca de estimaciones. Insumo de otro comercio responde 404. Las patas de una transacción de inventario se ordenan por unidad para no trenzar dos transacciones (evitar interbloqueos). Toda corrección sobre el tema licenciado (que vive fuera de git) viaja como comando versionado, no como edición directa.

#### [KDS] Nace el KDS: la venta congela lo que la cocina necesita y la tablet entra sin ser nadie
<sub>`d8d2ce6` · `66da35c` · `9216bc0` · `f73b0ce`</sub>

**Qué.** Tres datos nuevos en la venta: order_lines.dispatch (cocina o barra, congelado AL VENDER), order_lines.notes (la nota por línea: «sin cebolla» es de ESE taco; en el carrito «2 sin cebolla + 1 normal» se separa solo) y orders.device_sold_at (la hora del reloj del cajero, no la del servidor). Pantalla KDS con tres estados (pendiente, en proceso, lista) a la que la tablet entra con código de comercio + PIN del puesto una sola vez, con token propio (no Sanctum) revocable de uno en uno. La comanda vive en kitchen_tickets, tabla mutable aparte, con clave (orden, área): dos cervezas y un taco son DOS comandas. El tablero es orders LEFT JOIN kitchen_tickets: pendiente es la AUSENCIA de fila — sin observer, sin job, sin backfill, sin reconciliador. Sondeo cada 3 s con ETag/304 (el ETag excluye server_time), control optimista por estado con 409 que devuelve la fila vigente, reloj en formato 24 h legible a tres metros. Fixes: el bucle de sondeo no arrancaba en una tablet recién dada de alta (encontrado en una Galaxy Tab real: una petición y luego nada); y el limitador del login del POS llaveaba por `email` en un endpoint que valida `username` — la llave efectiva era «|IP» y en un festival, donde todas las cajas salen por el mismo router, la sexta tablet recibía 429 sin que nadie fallara.

**Por qué.** dispatch vivía en categories.dispatch, que es mutable: recategorizar un producto en enero reescribía qué comandas fueron de cocina en diciembre. notes queda fuera de la comprobación de idempotencia a propósito: un borrador guardado antes de que existieran las notas se reenvía sin ellas y es la MISMA venta. device_sold_at existe porque el POS cobra sin red: paid_at es cuándo se enteró el servidor, y sin la hora real la cocina recibe como recién llegado un pedido por el que el cliente ya reclama; se descarta si viene del futuro o de hace más de un día (eso es un reloj mal puesto, no retraso). La comanda NO puede vivir en la orden: una orden cobrada es historia inmutable y el POS envuelve venta y cobro en una transacción, así que todo lo que ve la cocina nace cobrado, o sea nace cerrado a escritura. La tablet no usa Sanctum y no es purismo: config/sanctum.php deja guard=web, así que en una tablet con /event-panel abierto la API autenticaría a ESA persona sin PIN; y prune-expired borra por created_at ignorando expires_at — todas las tabletas morirían a los 15 días en silencio. Sondeo y no websockets por aritmética: el suelo de latencia es el outbox del POS; optimizar servidor→tablet mientras el tramo anterior tarda minutos es optimizar el eslabón equivocado (lo que sí acorta es bajar el empuje del outbox a 5 s con bandeja no vacía). El 409 por estado existe porque volver atrás es legal: sin él, el ayudante con pantalla de hace 3 s deshace lo que la cocinera marcó sin que nadie se entere.

**Garantías.** Orden y líneas son inmutables tras el cobro: Order::updating lanza mirando el estado ORIGINAL sin importar la columna, assertRowIsWritable reconsulta la fila real para que ni un update por clave lo esquive, OrderLine::updating lanza siempre; los únicos hechos mutables son kitchen_tickets y Refund. Pendiente = ausencia de fila (nada que reconciliar). La comanda es el par (orden, área), nunca la orden. vendor_id es NOT NULL en las tablas del KDS, al revés que en orders: VendorScope falla ABIERTO y esa columna es el último backstop contra que un comercio lea las comandas de su competidor. setPermissionsTeamId(null) hace que cualquier ->can() que se cuele en la tablet devuelva false. El ETag excluye server_time (o el 304 no ocurriría jamás). device_sold_at del futuro o de más de un día se descarta. El bucle de sondeo arranca siempre y es cada vuelta la que decide si hay algo que pedir.

#### [Plataforma] Confiar en el proxy: sin trustProxies, detrás de un túnel todo sale en http
<sub>`194bff0`</sub>

**Qué.** trustProxies para cualquier proxy (at: '*'). Verificado por el túnel: los assets de Vite salen como https://payrone.ngrok.app/build/... y el login redirige a https.

**Por qué.** A esta aplicación se llega siempre por delante de algo que termina el TLS —hoy el túnel de pruebas, mañana un balanceador—. Sin trustProxies, Laravel ve la petición como http y construye TODAS sus URLs con http://: assets bloqueados por contenido mixto, redirecciones que sacan al usuario de la sesión segura, cookie sin marcar Secure. Se confía en cualquier proxy porque no hay forma de conocer de antemano las direcciones de un túnel ni de un balanceador gestionado.

**Garantías.** Es seguro SOLO mientras nadie pueda hablar con el servidor saltándose el proxy; el día que se exponga el puerto directamente hay que acotar las direcciones. Deuda declarada que reaparece en el commit del índice ciego (d88dc2a): con at:'*' el limitador por IP se puede esquivar falsificando X-Forwarded-For, y acotarlo es una decisión de despliegue todavía pendiente.

#### [KDS] Los tres tiempos: los relojes de la cocina, con nombre, principio y final
<sub>`ac6c90a` · `0c3c704` · `14cf8f8`</sub>

**Qué.** Tres relojes nombrados en la tarjeta del KDS: espera del cliente (de pagado a listo, se congela), en cola (de llegar a cocina a que alguien la empiece — mide manos libres, no destreza) y preparando (se congela); más «en el pase», que sigue vivo (cuánto lleva hecha sin entregarse). El color de la tarjeta sale del reloj VIVO de cada estado; los parados se pintan en gris. Informe de tiempos en las dos puertas con mediana y p90 calculados en PHP por rango más cercano, más un cuarto tramo que nadie pidió: el retraso de sincronización. Dos arreglos de los jueces: una venta mixta con la barra servida y la cocina sin tocar desaparecía del recuento de abiertas (el left join filtrando ready_at nulo descartaba la fila de la barra y con ella la venta entera — justo la comanda que peor va se esfumaba); y el veredicto de cuello de botella comparaba medianas de poblaciones disjuntas sin pesarlas (cinco ventas offline de doscientas bastaban para culpar al wifi, y podía imprimir «el 300% de lo que esperó el cliente»). El MISMO parcial se enseña también en la pestaña del comercio en /event-panel, con el número de comandas sin cerrar en rojo.

**Por qué.** Al marcar LISTA el reloj del cliente seguía corriendo: ya no medía lo que el cliente esperó sino cuánto tarda alguien en recoger, que es otra cosa y de otro — un cronómetro que no para después de su hecho deja de medir ese hecho. El color jamás sale de la espera del cliente porque incluye el retraso de la red y culparía a quien cocina de un problema de wifi. Mediana y p90 y jamás la media: una comanda que alguien olvidó marcar destroza una media y no dice nada. Percentiles en PHP porque MySQL y SQLite no comparten función de percentil y las pruebas corren SQLite. El retraso de sincronización se separa porque el POS cobra sin red: metido en el tiempo de cocina, el informe diría «esta cocina es lenta» cuando el problema era el wifi. La pestaña del comercio existe porque son dos preguntas legítimas distintas: «¿qué puesto de mi festival va lento?» se responde comparando en el evento; «¿por qué va lento ESTE comercio?» donde ya se mira todo lo suyo. Es el mismo parcial y no una copia: dos copias divergirían y el mismo puesto enseñaría cifras distintas según por dónde se entrara — la peor forma de romper la confianza en un dato.

**Garantías.** Espera del cliente y preparación se congelan al cerrar su hecho. La media no se usa jamás en tiempos de cocina: mediana y p90. El retraso de sincronización nunca se mezcla con el tiempo de cocina. Un tramo no manda en el veredicto de cuello de botella si no cubre al menos la mitad de las comandas que sostienen la espera, y su peso se recorta a cien. Las áreas abiertas de una venta se derivan de order_lines.dispatch restando las servidas (con test). El parcial de tiempos es único y compartido entre las pantallas; el número rojo de comandas sin cerrar es el antídoto contra el sesgo de supervivencia y hay que mirarlo antes de creerse ninguna mediana.

#### [APK (tablet)] El cascarón Android del KDS: un WebView a pantalla completa, y por qué no Capacitor
<sub>`62bb58f`</sub>

**Qué.** Repo payrone-table-kds: app Kotlin (MainActivity.kt, Ajustes.kt) que es solo un WebView a pantalla completa apuntando a https://SERVIDOR/event-kds. Configura FLAG_KEEP_SCREEN_ON (pantalla siempre encendida), mediaPlaybackRequiresUserGesture=false (el pitido de comanda nueva lo genera la web con AudioContext y llega sin gesto: sin esta línea no suena jamás), domStorageEnabled (el token del dispositivo vive en localStorage; sin esto cada arranque pediría código y PIN otra vez), oculta barras de sistema, desarma el botón atrás, y pinta una pantalla de error en español con la dirección y botón de reintentar en vez del dinosaurio de Chrome. La dirección del servidor se teclea una vez a mano (kds.payrone.do → https, 192.168.1.50:8000 → http) y para volver a esa pantalla hacen falta siete toques en la esquina superior izquierda. Probado en Galaxy Tab S10 FE con Android 15: alta con código y PIN, tablero real, transición atribuida a la tablet, corte de wifi y recuperación.

**Por qué.** Toda la lógica de cocina (los tres estados, el alta por PIN, el sondeo, los relojes) vive en la app Vue que sirve Laravel, y no debe haber nada de eso en el APK: arreglar algo en mitad de un festival es desplegar el servidor, no reinstalar el APK en veinte tablets. NO es Capacitor y no contradice el ADR-005: aquel eligió Capacitor para el POS porque allí hacen falta SDKs nativos (cobro con Portal, impresora SUNMI); el KDS no necesita ninguno y ni siquiera tiene modo sin conexión, así que Capacitor solo añadiría una cadena de build de npm y una copia del front que se desincroniza del servidor. El cascarón solo resuelve lo que un navegador no puede (wakelock fiable, audio sin gesto, quiosco). Se descartó un botón visible para cambiar el servidor: lo acabaría pulsando alguien con la mano llena de harina y cambiar el servidor deja la cocina a oscuras.

**Garantías.** Frontera del cascarón: cero lógica de negocio en el APK, los arreglos de cocina se despliegan en el servidor. La pantalla de error solo salta ante el fallo de la página principal (onReceivedError filtra a isForMainFrame): un sondeo que falla lo cuenta la propia web con su reloj de frescura, y taparlo escondería las comandas que sí están. No funciona sin conexión a propósito: una pantalla de cocina con datos viejos es peor que una que dice que no tiene datos. No guarda nada salvo la dirección del servidor: duplicar el token aquí sería tener dos verdades sobre quién es esta tablet.

#### [APK (tablet)] El vigía: contra la pantalla negra que parece viva
<sub>`9190557`</sub>

**Qué.** Un watchdog en el cascarón que cada 3 segundos pregunta cuántos hijos tiene el contenedor #kds. Si pasan 12 segundos sin ninguno, recarga; a la tercera recarga fallida, saca la pantalla de error (que al menos tiene botones). Además onRenderProcessGone devuelve true para que la app sobreviva y se recree cuando el renderer del WebView muere, en vez de que el sistema mate la app y deje la tablet en el escritorio de Android.

**Por qué.** Lo encontró la revisión adversarial y sobrevivió al refutador: el HTML de /event-kds pesa un kilobyte y entra con cualquier señal, pero el bundle pesa cientos y puede cortarse o quedarse colgado sin fallar nunca. El resultado es un div#kds vacío sobre el mismo fondo #090e1a del arranque: pantalla encendida, sin comandas, y el cocinero concluye que no hay pedidos. Ni onReceivedError lo veía (filtra a main frame a propósito) ni la web podía avisar (el reloj que lo contaría vive en el mismo renderer que no arrancó): solo el cascarón puede. El vigía arranca al llamar a loadUrl y NO en onPageFinished, porque con la petición colgada ese evento no llega nunca y un vigía anclado ahí no se armaría jamás. Se para en onPause porque web.onPause() suspende el JavaScript y habría una recarga espuria cada vez que la tablet pasa a segundo plano. Comprobado en tablet: ir a Inicio y volver no provoca recargas.

**Garantías.** La sonda del vigía (#kds con hijos) es la señal de vida del tablero, no los callbacks del WebView. El vigía nunca se ancla a onPageFinished. Se para en onPause. Un WebView con el renderer muerto no es uno vacío: es inutilizable, y onRenderProcessGone debe devolver true.

#### [Plataforma] ADR-007: una puerta por audiencia y por modalidad
<sub>`a3b4289` · `d6e08b3`</sub>

**Qué.** Primero: cada audiencia tiene su puerta con su guard fail-closed — el panel privado del comercio (/comercio, luego /event-vendor) con el middleware que ejecuta la matriz de rebotes, y las operaciones compartidas sin duplicarse: traits (HandlesVendorCatalog/Inventory) y vistas parciales parametrizadas que sirven igual al organizador y al encargado. Después, la adenda: las puertas se renombran por MODALIDAD — /saas-admin; event-panel, event-vendor, event-pos para festivales; /business y /pos para el negocio.

**Por qué.** El plan del ADR-006 mezclaba dos audiencias (organizador y encargado) en una puerta con guards cruzados. La adenda corrige además una recomendación equivocada de la propia sesión de trabajo: se propuso un /panel único adaptativo, lo cual violaba el principio fijado desde julio — «las reglas de los mundos NO deben ser condicionales en código compartido» — y los datos lo confirmaron (ocho condicionales para adaptar solo el dashboard). /vendor se descartó como nombre del mundo negocio porque en el código Vendor ES el comercio de evento y la colisión semántica costaba más que el renombrado.

**Garantías.** El comercio de un usuario es implícito por su usuario, jamás elegido por URL (por eso la puerta es singular). La entrada se decide por capacidades, no por nombre de rol. Y la más cara: los dos POS comparten motor — duplicar el offline (outbox, sync idempotente, arqueos) sería el error más costoso del sistema; si las modalidades divergen en caja, el punto de corte es el componente de pantalla, nunca el motor.

#### [Documentación] Documentación v0.4: auditoría doc por doc contra el código y el nuevo estado actual
<sub>`35076cb`</sub>

**Qué.** Auditoría de los docs 02, 03, 04, 05, 07, 08 y README contra el código real. Se corrige lo que la v0.1 daba por hecho y no existe (módulos Fiscal y Reporting, API versionada, sincronización por lotes, Alpine, S3) y se documenta lo construido: puertas por audiencia y modalidad, entrada única, doble eje de aislamiento, el dominio de ventas con todo lo que congela, la API del POS y el motor offline. Nace 00-estado-actual.md: qué funciona hoy, las reglas que el código no deja romper y lo que falta con su prioridad.

**Por qué.** Los documentos describían un sistema que no existía y eso es peor que no tener documentos. El README instaura la regla de lectura: cada documento lleva su columna de frescura, y cuando un documento y el código se contradigan, manda el código. También fija por escrito las divergencias entre ADR y realidad (ADR-003 implementado sin lotes, ADR-004 sin construir, ADR-005 solo la PWA) y la lista de lo NO construido para que nadie lo busque en el código.

**Garantías.** Cuando doc y código se contradicen, manda el código. El doc 00 es la fuente de la realidad operativa; los demás describen el diseño.

#### [Negocio] ADR-008: /business, la casa del negocio independiente, y el apagado de /app
<sub>`942dd3f`</sub>

**Qué.** La puerta del mundo negocio con ocho secciones (resumen, menú, inventario, ventas, caja, sucursales, equipo, ajustes), tres pantallas que no existían en ningún panel (histórico de arqueos de solo lectura, ajustes fiscales con itbis_mode por fin editable por el dueño, e inventario avanzado: conteo, merma, traslado, umbral y libro mayor), y el apagado del panel Filament /app — tras construir en /event-panel las cinco capacidades que solo vivían allí y rescatar las reglas de negocio que sus ~80 tests protegían.

**Por qué.** El dueño del bar caía en el panel del organizador de festivales y el cajero quedaba en un callejón sin salida. Las decisiones que no se deducen del código: (1) la frontera vive en la puerta — EnsureBusinessUser exige positivamente instanceof BusinessAccount y LIMPIA VendorContext, porque VendorScope falla abierto y un contexto colado escondería el catálogo entero del bar sin un solo error; (2) el catálogo es de la CUENTA, no de la sucursal — no existe columna que ate un producto a un local: un solo Mojito a un solo precio para todas, y una carta por local sería una tabla puente nueva, no un ajuste de pantalla; (3) no existe vínculo usuario-sucursal — cualquier gerente ve todas, y si algún día hace falta se inventa la relación y se filtra explícito, por puerta y no con condicionales; (4) la propina legal no es venta — viaja dentro de total_cents, y los reembolsos la prorratean en la proporción devuelta porque refunds guarda un importe plano: sin ese prorrateo una venta con propina reembolsada entera daba ventas negativas; (5) los traits del catálogo se duplican a propósito como gemelos del mundo eventos, para que las dos lecturas del mundo no acaben decidiéndose con un if compartido. Cambio de doctrina al apagar /app: quien solo opera caja ya no recibe un 403 seco, se le manda al POS — un callejón sin salida no es una medida de seguridad.

**Garantías.** SalesSummary separa cobrado, devuelto, propina y venta con la identidad ventas + propina + devuelto = cobrado; jamás sum(total_cents). Dos preguntas, dos consultas: SalesSummary corta por paid_at (cuánto me quedé de lo vendido), NetSales por el día de la devolución (cuánto salió de la gaveta, para cuadrar el arqueo). Abrir y cerrar caja solo ocurre en el POS, junto al dinero: cerrar un turno desde una oficina no es un arqueo. Apagar /app no desacopló el dominio de Filament: el paquete queda por /saas-admin y 19 enums implementan sus contratos.

#### [KDS] ADR-009: el KDS — la comanda como hecho nuevo y la tablet como dispositivo
<sub>`208b847`</sub>

**Qué.** Pantalla de cocina en tablet con tres estados (Pendiente, EnProceso, Lista) para los puestos de evento. La comanda vive en kitchen_tickets, tabla propia y MUTABLE al lado del dominio inmutable de ventas (como Refund): un hecho nuevo que referencia la venta, jamás una edición. PENDIENTE es la ausencia de fila (el tablero es un LEFT JOIN): sin observer, sin job, sin backfill, y ninguna venta puede perderse. La clave es el par (orden, área): una venta mixta genera una comanda para barra y otra para cocina que avanzan por separado. La tablet se ENROLA con código de comercio + PIN una sola vez y vive de su propio token — sin Sanctum, por dos fallos comprobados: guard=['web'] autenticaría a cualquier sesión web abierta en la tablet, y prune-expired borra tokens por created_at, apagando todas las tabletas de más de quince días en silencio. Sondeo cada 3 s con ETag (calculado excluyendo server_time, o el 304 no ocurriría jamás) en vez de websockets. Control optimista por estado: cada toque manda el estado del que venía y un 409 devuelve la fila vigente. Cuarta app de Vite (/event-kds), sin service worker.

**Por qué.** Cuatro hechos del código, comprobados antes de decidir, condicionaron todo: la orden cobrada es historia inmutable (por eso la comanda no puede vivir en la orden); el área de despacho vivía en categories.dispatch, mutable (recategorizar en enero habría reescrito las comandas de diciembre — por eso se congela order_lines.dispatch al vender); el sistema no sabía a qué hora se cobró en el dispositivo (por eso device_sold_at, descartado si viene del futuro o de hace más de un día); y no hay broadcasting montado. Los websockets se descartaron por aritmética: el suelo de latencia es el outbox del POS (minutos con mal wifi) — optimizar el tramo servidor-tablet de 3 s a 50 ms es optimizar el eslabón equivocado. El cursor incremental se descartó por una carrera del keyset cuyo síntoma sería «a veces un pedido no sale», intermitente y carísimo un sábado a las nueve; el snapshot completo se autorrepara. Sin bandeja de salida, a diferencia del POS: una venta es un hecho local que el servidor acabará aceptando, pero un estado de cocina es una verdad compartida y viva — sincronizar tarde un «marqué lista» resucitaría un estado viejo en todas las pantallas. Sin service worker porque el del POS controla el origen entero y un segundo rompería intermitentemente el arranque offline del POS, la pieza más delicada del sistema. Fuera a sabiendas: estado por línea, libro de transiciones, informes de tiempos (mezclarían retraso de red con tiempo de cocina), cocina compartida entre puestos y migrar el POS a token propio.

**Garantías.** El camino del dinero solo se toca para congelar dos datos en el creating (dispatch y device_sold_at); device_sold_at NO se usa jamás para dinero, numeración ni cortes de día. Las comandas pendientes y en proceso nunca caen del tablero (un pedido olvidado tiene que seguir gritando); Lista es terminal y cae a los veinte minutos. Volver atrás borra el sello de hora deshecho. kitchen_tickets.vendor_id y kds_devices.vendor_id son NOT NULL como backstop de base de datos contra el fail-open de VendorScope, con el abort_unless del middleware por encima — redundante a propósito. Trampa documentada para que nadie la repita: reutilizar SetTenantContext o EnsurePosCapability daría 200 con el tablero vacío, cero excepciones, cero logs. El reloj de frescura va siempre visible y a los 15 s sin respuesta sale franja roja: una pantalla congelada que parece viva es peor que una caída.


### 2026-08-03

#### [Eventos] Comandas en vivo: el mapa del festival mientras está pasando
<sub>`f81f441` · `7797e60`</sub>

**Qué.** Menú «Comandas» y tablero en vivo del organizador: agrupado POR COMERCIO (no en tres columnas globales), cada uno con sus contadores y su comanda más vieja sin cerrar, los peores arriba. Es de SOLO LECTURA y la pantalla lo explica. Reutiliza KitchenBoard —la misma consulta que alimenta la tablet— y lo pinta entero el JavaScript, primer vistazo incluido. panel:fix-theme-links aprendió a AÑADIR entradas de menú que faltan, idempotente. Enlaces «Ver las comandas en vivo» desde los tiempos del evento y desde la pestaña del comercio, cada uno con su filtro puesto (el enlace entra por parámetro porque el parcial se comparte entre tres pantallas). Dos mentiras corregidas al día siguiente por la revisión adversarial: la pantalla decía «elegido por ser el que está en marcha» aunque hubiera caído al último festival terminado (ahora en ámbar y con otras palabras), y el sondeo era un setInterval sin guardia sobre una función async — si el servidor tardaba más de 5 s cada vuelta apilaba otra petición y el servidor iba peor por culpa de la pantalla que lo medía.

**Por qué.** Una columna «pendiente» con las comandas de ocho comercios mezcladas no dice a qué puesto hay que ir; nadie mira la tercera pantalla de una lista. Solo lectura y no falta el botón: marcar una comanda es un acto de quien cocina, delante de la plancha — si se marcara desde la oficina, los sellos de hora dejarían de decir cuándo se hizo el plato y el informe de tiempos entero dejaría de medir la cocina. Misma consulta y mismo renderizador porque dos de cualquiera divergirían: el organizador vería una comanda que el cocinero no ve. Leer «en marcha» sobre un festival de hace tres semanas hacía dar por bueno que no hay nadie esperando.

**Garantías.** El tablero del organizador es de solo lectura: marcar comandas es exclusivo de quien cocina. Un solo tablero: KitchenBoard alimenta tablet y panel, y un solo renderizador lo pinta. Los sondeos del panel llevan guardia de reentrada. Las modificaciones al menú del tema licenciado son idempotentes y versionadas.

#### [APK (tablet)] La tablet cuenta su batería, y el panel distingue medir de saber
<sub>`fff0cb8` · `fa8476f`</sub>

**Qué.** La batería viaja como CABECERA en el mismo sondeo de comandas cada 3 s: sin endpoint nuevo, sin temporizador nuevo. Dos fuentes: en el APK, el único puente nativo window.PayroneKds.bateria(), de solo lectura, con su regla de ProGuard (sin ella el método desaparecería del APK firmado y el puente moriría solo en producción); en navegador normal, getBattery(). Si no hay ninguna, NO se manda nada. Escritura con freno, pero EN EL ACTO si cambia el estado de carga o el nivel cae de golpe. Tras la revisión: battery_at (cuándo se midió ESTO; solo se mueve si la medida cambia) se separó de last_seen_at (cuándo se supo de la tablet; se toca siempre) — el feed manda las dos y el borde punteado del panel usa la segunda. Comprobado en una Galaxy Tab S10 FE contra adb dumpsys.

**Por qué.** Un puesto va con batería externa y la app va sin barra de estado a propósito: una tablet que se apaga a las once deja un puesto ciego igual que una comanda atascada, y es más fácil de evitar. En CABECERA y no en la URL, y esa es toda la decisión: en la query sería un parámetro que cambia solo, cambiaría la URL y adiós al If-None-Match y al 304 que hace barato el sondeo. Sin fuente no se inventa un valor: un 100 de relleno diría que todo va bien sin saberlo y un 0 avisaría de una batería agotada que no existe. El puente no decide nada — lee un hecho físico; el umbral de aviso es del servidor, así que se cambia desplegando y no reinstalando veinte APK. battery_at reescrito cada minuto era falso («medido ahora» de una lectura de hace media hora) y rompía el ETag; pero arreglar solo eso empeoraba: una batería quieta al 64% envejecería aunque la tablet conteste cada 3 s, y el panel la daría por apagada justo por estar estable. Eran dos preguntas distintas y hacían falta las dos.

**Garantías.** La batería viaja en cabecera, jamás en la URL (el 304 no se toca). El puente nativo del APK es de solo lectura y no toma decisiones: los criterios viven en el servidor. Sin fuente real no se envía nada — no se inventan valores. battery_at solo se mueve si la medida cambia; last_seen_at se toca siempre y es lo que decide si el número es de fiar.

#### [KDS] La tablet tiene identidad, y el panel deja de mentir sobre las tabletas
<sub>`da3ba8b` · `19dfd45`</sub>

**Qué.** device_identity con ANDROID_ID: la misma tablet en el mismo puesto REUTILIZA su fila con token nuevo (sus comandas siguen siendo suyas); en otro puesto, la anterior se revoca (un aparato está en un sitio a la vez); sin identidad (navegador), fila nueva. El guard de updating no se debilitó: se le abrió una puerta con nombre, guardarReenrolamiento(), con la misma figura que saveTransition() de KitchenTicket. Después, cuatro mentiras del panel corregidas de raíz: «tabletas sin batería» contaba revocadas y calladas (ahora las revocadas no llegan al cuerpo y la cuenta la hace el navegador mirando el LATIDO, no la medida — dos alarmas separadas: «llevar un cable» en rojo, «ir a ver qué pasa» en ámbar); el comando de fusión podía apagar una tablet viva (ahora no toca filas con señal en la última hora ni con identidad, y con --aplicar imprime la tabla y pregunta, con false por defecto para que --no-interaction no revoque nada); el filtro de identidad vivía solo en el cliente «o sea que no existía» (se movió a EnrollKdsDevice: normaliza, rechaza los comodines conocidos de ANDROID_ID —9774d56d682e549c y la familia de un carácter repetido, exactamente las cadenas que fundirían media flota en una fila— y NO trunca a 64); y el guard del APK dejó de comparar texto: esDelServidor() desmonta el origen con Uri (esquema, host, puerto con 443/80 resuelto) y shouldInterceptRequest corta TODOS los subrecursos de fuera.

**Por qué.** Había seis filas «Cocina 1» y todas eran la misma Galaxy Tab: cada pérdida de token o recolgada fabricaba otra, y el panel enseñaba fantasmas con batería congelada. No es el número de serie: Build.getSerial() exige READ_PRIVILEGED_PHONE_STATE desde Android 10 y devolvería «unknown» en toda la flota; ANDROID_ID es estable por aparato Y por clave de firma. Y NO es una credencial: si sirviera para saltarse código+PIN, cualquiera que averiguase dieciséis caracteres —que el propio aparato reparte— entraría en un puesto ajeno. Sin identidad, un hueco es más honesto que una huella inventada. Dos tabletas llamadas «Cocina 1» en la misma cocina son el caso normal, no el raro. Truncar a 64 volvía a fundir lo que se quería separar. La identidad salió del validate() del controlador porque un 422 por identidad rara dejaba fuera a quien tecleó bien el código y el PIN. En el APK, comparar texto dejaba encajar https://kds.payrone.do.atacante.com; y addJavascriptInterface mete el puente en TODOS los marcos — un iframe no es una navegación, así que había que cortar los subrecursos. Se intercepta todo a propósito: el KDS lo sirve entero el mismo servidor; el día que alguien meta algo de fuera lo verá roto y tendrá que abrir la puerta a mano — que es justo cuándo toca releer si el puente sigue siendo aceptable.

**Garantías.** device_identity JAMÁS autentica: es una etiqueta para saber en qué fila escribir, y se lee DESPUÉS de que código y PIN hayan pasado (hay un test que lo fija). device_identity es inmutable: una fila que cambiara de aparato se llevaría el rastro de las comandas del anterior. operating_unit_id y vendor_id siguen bloqueados incluso con la puerta de reenrolamiento abierta. El comando de fusión con --no-interaction no revoca nada. Las filas revocadas no llegan al cuerpo del feed. El APK solo carga subrecursos de su propio servidor. La frescura se juzga por el latido (last_seen_at), nunca por la medida de batería.

#### [KDS] El pedido devuelto dejaba de existir para la caja y seguía en la cocina
<sub>`517b3c2`</sub>

**Qué.** Regla de dos condiciones para que una venta devuelta salga del tablero y del recuento de comandas abiertas del informe de tiempos: devuelta ENTERA (propina incluida) y SIN TOCAR. Una devolución parcial o una comanda ya empezada se quedan en pantalla con su franja de «devuelta». Probado sobre los datos reales del túnel: la devuelta entera sin tocar desaparece (4 → 3), la que ya estaban cocinando se queda.

**Por qué.** RefundOrder no escribe en orders.status A CONCIENCIA: la venta sigue siendo lo que fue y el dinero que vuelve es un hecho nuevo que la referencia. Pero el tablero leía «cobrada» y la enseñaba como Pendiente para siempre, con un reloj que no paraba: cada noche arrastraba a su comercio al primer puesto por un plato que nadie iba a hacer. Un reembolso es un importe, no unas líneas: con uno parcial nadie sabe si volvió el refresco o el plato, así que decide la cocina, que para eso ve la franja. Devolver la comida y quedarse la propina deja una venta viva. Y si alguien ya la empezó, la está cocinando ahora mismo: hacerla desaparecer dejaría a esa persona con un plato en la plancha y sin explicación. Inflar el recuento de abiertas con ventas deshechas rompía el antídoto contra el sesgo de supervivencia: hacía creer que había gente esperando que no existía.

**Garantías.** RefundOrder nunca toca orders.status: el reembolso es un hecho nuevo que referencia la venta. Solo la devolución total (propina incluida) y sin tocar cierra la comanda automáticamente; en cualquier otro caso decide la cocina. Una comanda empezada nunca desaparece sola de la pantalla.

#### [KDS] La comanda zombi y la búsqueda que se apagaba a medianoche
<sub>`38a0068`</sub>

**Qué.** La regla «si la línea no congeló su área, va al área que declara el puesto» estaba escrita tres veces y un cuarto sitio la copiaba a mano: ahora vive en DispatchArea::porDefecto() y todos la consumen (tablero, avance, informe de tiempos). Con default => Kitchen y no un match exhaustivo, a propósito. También: items_count se congelaba contando menos unidades de las que enseña el tablero, y la ventana de la búsqueda «¿y lo mío?» pasó a ser el MÍNIMO entre el inicio del día local y el inicio de la ventana rodante del tablero, leída de KitchenBoard en vez de repetida. Tres tests nuevos que fallan con el código de antes con los tres síntomas exactos: el 422, el items_count en 2 en vez de 5, y la búsqueda vacía a las 00:10 con el tablero pintando la tarjeta.

**Por qué.** El único de los tres puntos que decide si la comanda AVANZA era el que no conocía la regla: el tablero pintaba la tarjeta con $linea->dispatch ?? $porDefecto pero el toque buscaba con where('dispatch', $area), que no casa NULL con nada — cada toque contestaba 422 «esta área no despacha nada»: una tarjeta colgada toda la noche sin forma humana de cerrarla, en la única pantalla del sistema donde no hay a quién llamar. El default no exhaustivo es deliberado: el día que nazca otra modalidad de puesto, mejor que su comanda salga en cocina a que la pantalla reviente. items_count era urgente y no cosmético: la cifra se congela al nacer la fila y el historial es inmutable, así que una cuenta mal congelada no se corrige nunca. La búsqueda por día de calendario se vaciaba a las 00:00 —la hora punta de un festival— mientras el tablero de doce horas rodantes seguía enseñando la tarjeta, contradiciendo lo que el docblock prometía.

**Garantías.** La regla del área por defecto vive en un único sitio: DispatchArea::porDefecto(). Ante una modalidad de puesto desconocida, la comanda cae a cocina en vez de romper la pantalla. La búsqueda contiene SIEMPRE al tablero — es la única relación entre las dos que no confunde a nadie. Toda cifra que se congela debe congelarse bien a la primera: el historial inmutable no admite corrección posterior.

#### [APK (tablet)] El puente con el aparato: bateria() e identidad(), lo único que la web pregunta a lo nativo
<sub>`54c35df` · `af148d9`</sub>

**Qué.** PuenteDelAparato.kt (antes «puente de batería»; se renombró al ganar el segundo método), expuesto como window.PayroneKds vía addJavascriptInterface, de SOLO lectura y con dos métodos: bateria() devuelve '{"nivel":87,"cargando":true}' o cadena vacía; identidad() devuelve el ANDROID_ID o cadena vacía. La web consume la batería por cabeceras del sondeo (X-Kds-Bateria, X-Kds-Cargando), nunca por URL, para no romper el If-None-Match y el 304 que hace barato el sondeo. La identidad viaja solo en el alta (POST /api/kds/enrolar, campo device_identity en el cuerpo) y no en el sondeo. Incluye la regla -keepclassmembers de ProGuard, sin la cual el minificador borra el método del APK firmado y el puente muere solo en producción. Comprobado en Galaxy Tab contra adb shell dumpsys battery.

**Por qué.** El WebView de Android no trae navigator.getBattery, la app esconde la barra de estado a propósito, y un puesto con batería externa no puede enterarse de que se queda sin ella: el panel del evento puede avisar a tiempo pero necesita el dato. La identidad existe para no duplicar filas en kds_devices (seis «Cocina 1» que son la misma tablet). Se usa ANDROID_ID y no Build.getSerial() porque el serial exige READ_PRIVILEGED_PHONE_STATE desde Android 10 y devolvería «unknown» en toda la flota. Se filtra el literal 9774d56d682e549c (un ANDROID_ID de fábrica repetido en miles de equipos) porque una identidad repetida es PEOR que ninguna: dos tablets se pisarían la fila. El puente no rompe la frontera del cascarón: no decide nada, lee dos hechos físicos; a partir de qué porcentaje se avisa sigue siendo del servidor, o sea que se cambia desplegando. addJavascriptInterface es una superficie de ataque real que se asume porque solo se lee, lo leído casi no es sensible, y el guard de orígenes acota quién llega a verlo. Descubrimiento medido, no supuesto: adb shell settings get secure android_id NO devuelve lo que ve la app (el shell tiene su propio valor acotado) — no hay que perseguir ese fantasma; y debug y release dan hoy la MISMA identidad porque ambos se firman con la clave de debug, lo que confirma que el valor lo acota la clave de firma y no el paquete.

**Garantías.** El puente es el ÚNICO punto donde la web habla con lo nativo, y es de solo lectura; antes de añadirle cualquier método que no sea de solo lectura hay que releer entera la sección del README sobre el guard. La cadena vacía significa «el sistema no lo da» y se respeta: un 100 de relleno mentiría y un 0 avisaría de una batería agotada que no existe — un dato que falta es honesto, uno inventado no. La identidad NO es una credencial: quien la presenta sigue dando código de comercio y PIN de puesto; usarla para saltarse el PIN abriría puestos ajenos con una cadena que no es secreta. El filtro de identidades en la tablet es cortesía, no defensa: el servidor vuelve a filtrar y lo que no pasa se ignora sin tumbar el alta. AVISO atado en el README: el día que release se firme con keystore propio, TODA tablet ya dada de alta cambiará de identidad y crearía filas nuevas — la firma de verdad y una migración de kds_devices tienen que ir juntas. La regla -keepclassmembers no se puede quitar.

#### [Seguridad] El guard de orígenes: de comparar texto a comparar orígenes, y la puerta de los subrecursos
<sub>`eb8e358`</sub>

**Qué.** esDelServidor() deja de usar startsWith sobre la cadena del servidor y desmonta el origen con Uri: esquema, host y puerto (con 443/80 por defecto resueltos), y compara los tres. Y se añade la segunda puerta: shouldInterceptRequest corta TODOS los subrecursos que no sean del servidor configurado, porque addJavascriptInterface mete el puente en todos los marcos y un iframe o un <script src> no son navegaciones — nunca pasaban por shouldOverrideUrlLoading. Los esquemas que no salen a la red (data:, blob:, about:) pasan sin mirar; lo cortado devuelve un 403 con cuerpo vacío (vacío y no nulo, porque hay WebViews que se caen con un WebResourceResponse sin cuerpo); un request nulo falla cerrado. También se corrigió el docblock de PuenteDelAparato, que describía el guard viejo.

**Por qué.** startsWith es un prefijo de texto, no un origen: https://kds.payrone.do.atacante.com empieza igual, y https://kds.payrone.do@atacante.com es peor todavía porque todo lo anterior a la arroba es usuario y el host real es atacante.com. Arreglar solo la navegación no habría servido: los subrecursos heredan el puente sin navegar. Se intercepta TODO a propósito porque el KDS lo sirve entero el mismo servidor (Laravel sirve el bundle Vue; no hay CDN, ni fuentes de Google, ni script de métricas), así que cortar lo ajeno no quita nada — y el día que alguien meta algo de fuera lo verá roto y tendrá que abrir la puerta a mano, que es justo cuándo toca releer si el puente sigue siendo aceptable. El puerto entra en la comparación porque en un festival el servidor es http://192.168.1.50:8000 y la misma máquina en otro puerto no es la nuestra.

**Garantías.** Las dos puertas se cierran juntas y ninguna ve lo que entra por la otra: shouldOverrideUrlLoading para navegaciones, shouldInterceptRequest para subrecursos; ambas comparan el origen DESMONTADO (esquema+host+puerto), nunca prefijos de cadena. Request nulo falla cerrado. Límite documentado en el README: los WebSocket no pasan por shouldInterceptRequest — no heredan código con el puente, pero es una razón más para que el sondeo siga siendo el If-None-Match de siempre.

#### [APK (tablet)] La pantalla de error se cura sola: reintento con retroceso y la sonda como única autoridad
<sub>`88f7552`</sub>

**Qué.** Reintento automático desde el cartel de error con retroceso 5/10/20/40 segundos y techo de un minuto; el cartel dice en voz alta cuánto falta («Reintento automático dentro de 12 s.») y avisa cuando el intento está en marcha, sin irse a negro durante la petición (con el servidor caído puede tardar medio minuto en morir en el timeout de TCP). El botón «Reintentar» prueba ya y borra el retroceso; el retroceso también vuelve a cero cuando aparece un tablero de verdad. La salida del cartel la decide la sonda del vigía (#kds con hijos), no onPageFinished, que solo adelanta el sondeo. De paso se arreglaron cuatro formas en que el reintento se volvía contra la tablet: el cartel pisaba la pantalla de alta con la dirección a medio teclear (ahora el alta manda sobre todo); los relojes resucitaban con la Activity en pausa por cargas en vuelo que morían tarde (ahora ningún reloj se arma fuera de primer plano y onResume rearma mirando qué pantalla hay); olvidarElRetroceso() ponía el contador a cero sin quitar el Runnable ya posteado y el tic calculaba una espera negativa que disparaba la carga en el acto; y la bandera única falloDeCarga hacía que el error de una carga vieja escondiera bajo el cartel una carga nueva que sí había ido bien (borrada: la página de error del WebView no tiene #kds y la sonda devuelve cero sola). El plazo se guarda como instante, no como cuenta atrás: una tablet que despierta media hora después reintenta de inmediato.

**Por qué.** mostrarElError() paraba el vigía y no quedaba ningún reloj vivo: las dos únicas salidas eran los botones, así que un corte de diez segundos dejaba la tablet colgada de la pared con el cartel hasta que alguien cruzara el recinto — la cocina ciega media hora por un parpadeo de wifi. Lo que costó dos vueltas fue elegir la SEÑAL de que el servidor volvió: el primer intento colgó la salida de onPageFinished y se descartó porque falla por los dos lados — con la petición colgada no llega nunca (lo mismo que documentó el vigía), y cuando llega puede ser sobre el HTML de un kilobyte con el bundle a medias, la pantalla negra contra la que existe el vigía; además app.mount('#kds') corre antes del evento load, así que el cartel podía quedarse puesto para siempre encima de un tablero que ya pintaba comandas. Un reintento silencioso se descartó porque se parece demasiado a una tablet colgada, que es el fallo que este cascarón existe para evitar.

**Garantías.** La sonda (#kds con hijos) es la ÚNICA autoridad sobre «esto cargó de verdad», en los dos sentidos — no se puede volver a colgar nada de onPageFinished sin releer el README. Ningún reloj se arma con la Activity fuera de primer plano; onResume es quien rearma. El alta manda sobre todo: nada quita de delante la pantalla de la dirección salvo su botón de guardar. El reintento nunca es silencioso y nunca deja la pantalla en negro. IMPORTANTE: este hito quedó SIN verificar en hardware (la tablet estaba desconectada); lo verde es un puerto de la máquina de estados a un simulador que prueba la lógica escrita, no que el WebView se comporte como se asume — los comportamientos a comprobar en tablet real son los documentados en la sección «Cuando el servidor no contesta» del README (retroceso y techo, cuenta atrás visible, el botón que borra el retroceso, la regla del ciclo de vida, el alta intocable, el plazo como instante).


### 2026-08-04

#### [KDS] El sondeo del KDS se hace indestructible: ETags que mentían y plazos que no existían
<sub>`11f5279` · `45d3145`</sub>

**Qué.** Dos tandas de endurecimiento del store del tablero. Primera (las comandas desaparecían y volvían con la venta siguiente): un 200 con JSON ilegible LANZA en vez de convertirse en éxito hueco emparejado con el ETag vigente; olvidarElTablero() vacía comandas Y etag desde salir(), el 401 y el alta (salir sin vaciar el ETag hacía que reenrolar la misma tablet recibiera un 304 sobre un tablero vacío — el arreglo del usuario reproducía el fallo); comandas y etag se asignan juntos del mismo cuerpo validado (fuera el ?? [] que traducía «no me lo dijo» por «no hay»); un tablero vacío no se cree nunca solo por un 304 (de N a 0 se repide entero en el acto, y vacío se reverifica sin If-None-Match cada 60 s); cabeceras de caché explícitas (un ETag sin caducidad es cacheable por heurística: el proxy del wifi del recinto podía contestar por el tablero); guardia de reentrada y sesion/revision contra respuestas fuera de orden. Segunda (un sondeo colgado dejaba la cocina a oscuras): plazo adaptativo del fetch — el doble de la última respuesta buena, escalando 8→16→24→8 al tercer plazo agotado, aprendiendo solo de las respuestas caras (el 304 solo puede subir la medida); aCiegas se enciende desde el arranque (antes exigía una respuesta buena previa: la tablet que nacía contra un servidor caído no avisaba nunca); el POST de avance se reintenta (verificado en el servidor: AdvanceKitchenTicket controla POR ESTADO, repetirlo o aplica o devuelve 409 con la fila ya en destino, que es el éxito que es); y el rescate al recuperar señal, que costó tres vueltas, terminó en un techo: tras un abandono, el siguiente sondeo es INTOCABLE. Medido en el peor caso (par offline+online cada segundo durante cinco minutos): antes 299 fallos y tablero jamás visto; ahora pinta a los 21 s con 24 sondeos completos.

**Por qué.** La firma de ambos fallos era idéntica —tablero en cero, frescura en verde, sin errores, y todo vuelve con la venta siguiente— y esa reaparición en bloque delataba al cliente, no a la consulta. Un fetch sin plazo nunca resuelve, y la guardia de reentrada —puesta para que dos sondeos no se pisaran— era justo lo que impedía pedir otro. Un tope duro no distingue «lento» de «muerto» porque lo único que los separa es que uno acaba contestando: con 8 s fijos, un enlace sano de 11 s no pintaba nunca. Cortar el sondeo con cada evento de red era peor: el online de Android salta por gusto y, medido, con pares offline+online cada 10 s sobre un enlace de 11 s el servidor sirvió 31 tableros y la pantalla no vio ninguno. Lo que faltaba no era un disparador más fino sino un techo. Sin corredor de JS en el repositorio, los bancos que midieron todo esto cargaron el store real bajo node fuera del proyecto; es la tercera vez que esta función se rompe por un sitio distinto, así que el porqué de cada guardia quedó en comentarios pegados a la línea que no se puede volver a escribir mal.

**Garantías.** La garantía central, textual: ninguna secuencia de eventos de red puede impedir que el tablero complete un sondeo contra un servidor sano — como mucho se abandona uno de cada dos. El rescate es una optimización; que la pantalla pinte es la corrección: cuando chocan, gana pintar. Un tablero vacío jamás se acepta solo por un 304. comandas y etag son atómicos y salen del mismo cuerpo validado. El ETag se reconstruye idéntico al reenrolar (fijado por test, escrito para quien vuelva a tocar el store). El plazo se aprende solo de respuestas con cuerpo, nunca del 304. Reintentar el avance de comanda es seguro porque el control es por estado. Las respuestas del feed llevan directivas de caché explícitas.

#### [Seguridad] El alta del KDS deja de regalar CPU: índice ciego y penitencia
<sub>`d88dc2a`</sub>

**Qué.** unidadDelPin() probaba el PIN contra CADA puesto del comercio (ocho barras = ocho bcrypt por petición anónima; con veinte puestos, 163 segundos de CPU por minuto de tráfico modesto, con el código del comercio impreso y pegado a la vista del recinto). Ahora un índice ciego —HMAC del PIN llaveado con la APP_KEY y salado con el comercio— localiza el puesto candidato y deja UN solo bcrypt. El índice se escribe al EMITIR el PIN (donde existe en claro y donde nacen todos). La huella ata sus TRES entradas (comercio, hash, llave): cambiar la APP_KEY invalida los índices, los puestos caen al camino lento —más caro, pero vivo— y se reindexan solos. El freno, que costó cinco vueltas, es la «penitencia»: un estado del COMERCIO (vive en vendors) bajo el cual no se cierra ninguna puerta — solo se deja de comprar el bcrypt del hash tonto para contestar que no a lo que ya se sabe que es que no. Colaterales arreglados: PosAuthController hacía Hash::make() dentro del Hash::check cuando el usuario no existía (dos bcrypt por fallo y un oráculo de temporización); el freno de 5/min del POS se esquivaba cambiando mayúsculas del usuario (60 adivinanzas/min contra una cajera); el limitador del alta contaba ACIERTOS (la undécima tablet de un montaje recibía 429); y el panel decía «Bloqueado hasta las 02:41» en treinta barras a la vez — mentira encendible por cualquiera con diez peticiones, porque un hecho del comercio se guardaba en columnas del puesto.

**Por qué.** Las cuatro vueltas fallidas del freno cayeron por la MISMA razón: un contador que sube quien ataca, sobre algo que él elige, dejando fuera a quien no falló. Colapsar la IP quitando X-Forwarded-For cerraba la falsificación pero dejaba /saas-admin/login cerrado para todo el staff con cinco peticiones por minuto — revertido, porque afilar la IP exige acotar trustProxies a los rangos del borde, decisión de despliegue no tomada. Un contador por comercio bloqueaba al comercio entero con el PIN CORRECTO y no caducaba. Uno por cuenta abría un oráculo de enumeración de usuarios sostenible con una petición por minuto. La salida fue cambiar el sujeto y la consecuencia: el sujeto es el comercio (es un teorema del índice ciego que un fallo no es atribuible a ningún puesto — si el índice señala, el bcrypt cuadra), y la consecuencia no es un rechazo. El bcrypt se queda porque el índice localiza, no autentica; y lo que se pierde está en el docblock sin adornos, comparado con lo que ya se podía: con la base robada, un PIN de seis dígitos contra bcrypt cae en minutos por puesto en una GPU alquilada — el bcrypt nunca fue lo que hacía seguro ese PIN frente a una base robada. Indexar solo al acertar habría dejado el parque entero sin índice el día del montaje, o sea el abanico intacto exactamente cuando importa.

**Garantías.** Un PIN correcto entra EXACTAMENTE igual con la penitencia encendida que apagada — por construcción, no por cuidado. El índice ciego localiza pero jamás autentica: el bcrypt final se mantiene. La huella del índice ata comercio, hash y llave: un cambio de APP_KEY degrada al camino lento vivo con reindexado automático, nunca a un rechazo silencioso. La penitencia es un hecho del comercio y vive en vendors: el organizador lee lo que es verdad (alguien teclea cosas raras contra su código, se apaga solo, no hay nada que hacer). Queda ABIERTO y declarado: con trustProxies(at:'*') el limitador por código y origen se estrena en cada petición falsificada, así que el techo real de una campaña contra un PIN de seis dígitos es el ancho de banda (~3 horas para medio millón de intentos); cerrarlo exige la misma decisión de despliegue pendiente de acotar los proxies.

#### [Documentación] Doc 10: el plan de la app móvil del asistente, fundado en lo que se vendió
<sub>`f3cf88d`</sub>

**Qué.** Plan completo de la app white-label que lleva el público del festival (iOS y Android, con la marca del EVENTO al frente), nacido de cruzar la presentación vendida a Bocao Food Fest 2026 (6 pilares, dos minutas, estrategia white-label, PDF de Boletu Enterprise y B-Access) con el inventario real del backend: 30 promesas que tocan el teléfono del asistente, qué existe, qué falta y en qué orden. Dos decisiones fundantes: la boleta se integra con Boletu (ya vive en producción con QR dinámico cada 30 s y transferencia; no se reconstruye — el gate es el contrato de API) y la app es UNA base Flutter con módulos que cada evento enciende y ordena desde un manifiesto servido por el backend — el patrón del KDS: cascarón tonto, cerebro en el servidor. Los flavors solo cargan la identidad de tienda, porque Apple 4.2.6 obliga a que cada evento publique bajo su propia cuenta de developer (DUNS tarda semanas: tarea del primer mes). Cuatro fases: 0 cimientos (despliegue productivo, ADR-010 futuro con la puerta event-app y la cuenta de asistente en tabla propia con OTP, manifiesto, infra de push), 1 lectura sin dinero (menús, itinerario, mapa ilustrado, boleta, esperas por puesto derivadas del KDS), 2 el dinero (monedero en centavos, pedido anticipado por canal mobile con la comanda entrando sola al KDS, ETA de KitchenTimings), 3 engagement (feed moderado, pasaporte, misiones, Bocao Cam, Wrapped).

**Por qué.** El plan dice lo que la presentación calla, para decirlo al cliente antes de que lo descubra: el «paga con un toque desde el teléfono» no existe en iPhone fuera del EEE (Apple no abre NFC-HCE en RD) — la respuesta honesta es QR de pago que escanea nuestro POS; la red privada del predio conecta puestos y terminales, NO los teléfonos del público (mitigación: payloads mínimos, ETag en todo, reintentos idempotentes); y el saldo remanente del monedero tiene implicaciones legales en RD que hay que resolver antes de la Fase 2. Gate duro previo a todo: el backend vive tras un túnel ngrok con APP_DEBUG=true — no se expone una API pública así. Flutter ganó a React Native (el puente nativo complica cámara/NFC/offline) y Capacitor se re-descartó: esta app es nativa en sus exigencias. Decisión de dominio anotada: el pedido móvil no pasa por ninguna gaveta — meterlo en la sesión del cajero contaminaría el arqueo. Y lo que las tiendas exigen aunque nadie lo venda: borrado de cuenta en la app, reportar y bloquear, clasificación por alcohol, privacidad Ley 172-13.

**Garantías.** La app suma pero nunca es requisito para comprar (dos poblaciones: con y sin teléfono). Toda cifra de la app sale de los desgloses, nunca de sum(total_cents). La puerta nueva debe esquivar las dos trampas de Sanctum ya documentadas en el KDS y replicar el abort_unless contra el fail-open de VendorScope. El push del estado del pedido es push real, no polling: el tablero del KDS no se reutiliza para miles de teléfonos.

#### [Documentación] Changelog maestro, especificación de la app y CLAUDE.md
<sub>`ae1d6f2` · `57d19c6` · `3181e96` · `a9ce59d`</sub>

**Qué.** Este archivo. La memoria de los tres repos en un sitio: 73 hitos con qué se construyó, por qué y qué garantías dejó, más un glosario de 85 términos propios y el índice de garantías transversales de más abajo. Con él, `docs/11-app-movil-especificacion.md` (los 14 módulos de la app, lo transversal, la cuenta del asistente como el modelo que ata boleta↔pulsera↔monedero, lo que exigen las tiendas y nadie vendió) y sendos `CLAUDE.md` en los dos repos de código. La documentación se mudó después a `eventbarrest` **fusionando el historial**, no copiando: los 17 commits de docs conservan su porqué.

**Por qué.** Todo estaba escrito, pero repartido entre 98 mensajes de commit, nueve ADR y once documentos: entender el sistema exigía reconstruir el árbol archivo por archivo, y eso lo pagaba cada persona nueva y cada sesión de IA, otra vez, desde cero. El porqué es lo único que no se puede reconstruir del código, así que es lo que más espacio ocupa. Vivía además en un repo sin remoto — un sistema documentado cuya memoria se pierde con la máquina no está documentado. El `CLAUDE.md` cierra el ciclo por los dos extremos: manda leer el índice de garantías antes de tocar, y **actualizar el changelog al terminar**, con formato; sin esa segunda mitad la memoria envejece en una semana.

**Garantías.** El CHANGELOG es la memoria común de los dos repos de código y vive solo en `eventbarrest`: duplicarlo daría dos versiones que se contradicen. Todo cambio con sustancia entra antes de darse por terminado. Los repos NO son independientes y sus acoplamientos están enumerados: renombrar `<div id="kds">` deja la tablet en la pantalla de error para siempre con el servidor encendido, y cambiar la clave de firma del APK duplica la flota entera en `kds_devices`.

#### [Eventos] La app móvil arranca: la puerta `event-app` y el cascarón que se pinta solo
<sub>`817fec7` · `36242f3` · repo `eventbarrest-app`: `b0b08b3` · `94b9ead`</sub>

**Qué.** Primer slice de la app del asistente, en dos repos contra un contrato acordado antes de escribir código. **Backend:** dominio `EventApp` con tres endpoints públicos —manifiesto, comercios y la carta de un comercio— con ETag/304, código público del evento (`events.public_code`, patrón del código del KDS), tabla `event_app_manifests` (columnas tipadas para la marca, JSON para la lista de módulos), `ContextResolver::forEvent()`, caché de respuesta de 10 s y ADR-010. **App:** proyecto Flutter con módulos independientes bajo `lib/modulos/` y un registro que mapea la clave del manifiesto a su widget; módulo Menús; los estados que no son el camino feliz; pantalla oculta de desarrollo.

**Por qué.** El manifiesto es lo que hace REAL el white-label: el binario no sabe nada de ningún festival —lleva a qué servidor preguntar y qué evento es— y la marca, los módulos, su orden y los textos viajan del servidor, así que un evento cambia de color o estrena sección sin recompilar ni pasar por una tienda. El agujero de aislamiento no era el que parecía: `VendorScope` falla abierto, sí, pero lo que de verdad cierra la puerta es filtrar por **participación** (`event_vendor`) — un organizador con dos festivales tiene todos sus comercios en el mismo tenant, así que sin ese filtro la app de un evento leería la carta de un comercio del otro con un 200 legítimo. El limitador por IP se retiró midiendo: con `trustProxies(at:'*')` quien ataca **elige qué cubo llenar**, así que no le frenaba y sí dejaba fuera al festival entero tras el NAT de su operador. Como el ETag ahorra red pero no servidor —un 304 hacía las mismas consultas que un 200—, la puerta se sostiene haciéndola barata: caché de respuesta (manifiesto 3→2 consultas, comercios 5→2, menú 8→4). Diez segundos porque todo el ahorro está en los primeros (a 1000 pet/min, 5 s ahorra 98,8 % y 60 s solo 99,9 %) y lo demás se paga en frescura.

**Garantías.** El binario de la app nunca contiene identidad de evento más allá del código: todo lo demás viene del manifiesto. Un módulo que la app no conoce se ignora en silencio, y uno sin `activo` cuenta apagado — los dos lados fallan cerrado. Un evento sin manifiesto configurado devuelve 200 de fábrica, nunca 404: el 404 significa «este código no es de nadie». Un evento cerrado o liquidado sigue sirviendo 200 con su `estado` — apagar la puerta al cerrar convertiría en error miles de apps instaladas el lunes. **La puerta no se cachea, solo el cuerpo**: sin token que revocar, revalidar en cada petición es la única revocación que existe, y suspender un comercio tira además la lista de todos sus eventos. En esta caché solo viajan datos planos: `config/cache.php` fija `serializable_classes => false` y los tests corren con store `array`, así que un objeto guardado se corrompería en producción sin que la suite se enterara. Queda ABIERTO y declarado: el techo de volumen del borde no existe y solo se puede poner delante del backend al desplegar.

#### [Eventos] Cómo llega la app a las tiendas: una por evento, y la primera bajo Boletu
<sub>`(decisión)`</sub>

**Qué.** Modelo de publicación decidido: **una app por evento**, con la primera —Bocao Food Fest— publicada bajo la cuenta de developer de Boletu, y de la segunda en adelante cada organizador bajo la suya (Boletu compila y sube añadiéndose como developer a su equipo). Descartado el binario agregador.

**Por qué.** El plan traía un supuesto sin marcar como decisión: que cada evento necesitaba su cuenta de Apple desde el día uno, con el DUNS —semanas— en el camino crítico. Al revisarlo aparecieron dos hechos que lo cambian. Uno: de quién es el código NO cuenta para la 4.2.6 — la regla mira cuántas apps casi idénticas hay en la tienda, así que «solo le cambiamos el skin» es justo el perfil que persigue, no una defensa. Dos: la propia guía nombra el modelo agregador («una app de eventos con entradas separadas para cada evento») como alternativa válida, y se descartó a conciencia porque mata el argumento central del pitch: que el festival vive dentro de SU marca, no dentro de la del proveedor. El orden elegido es de bajo riesgo porque la 4.2.6 castiga el PATRÓN y no la app: una sola app de festival bajo Boletu no tiene con qué compararse, y de la segunda en adelante publicar bajo la cuenta del cliente es literalmente lo que la regla prescribe. La red de seguridad es que Apple permite transferir apps entre cuentas conservando reseñas y usuarios instalados, así que publicar bajo Boletu es un préstamo de cuenta y no un camino sin vuelta.

**Garantías.** El DUNS sale del camino crítico de Bocao 2026 y pasa a plan B en el cajón. Cada app sigue necesitando su flavor —bundle id, icono, nombre— porque son fichas distintas en la tienda, así que la arquitectura de manifiesto no cambia y serviría igual para el modelo agregador si algún día se quisiera para clientes pequeños. Las condiciones de transferencia entre cuentas hay que verificarlas ANTES de necesitarlas.

#### [Plataforma] La cuenta del asistente: entrar sin contraseña, y el primer actor sin tenant
<sub>`616b092` · repo `eventbarrest-app`: `0b3aa94` · ADR-011</sub>

**Qué.** Segundo slice de la app del asistente, en los dos repos contra el contrato ampliado. **Backend:** tres tablas de PLATAFORMA sin `tenant_id` (`event_app_accounts`, `event_app_sessions`, `event_app_login_codes`, registradas como excepción en las dos convenciones de aislamiento), entrada por código de 6 dígitos al email (sin contraseña), tokens con el patrón de la casa (tabla propia, sha256, revalidación completa por petición — no Sanctum, cuyas dos trampas ya mordieron al KDS), transporte de correo como interfaz (log en local; en producción sin proveedor FALLA RUIDOSO en vez de escribir códigos en claro), y seis endpoints bajo `/api/event-app/cuenta` incluido el borrado real que exige Apple. **App:** avatar en el cascarón (no módulo), flujo email → código → sesión, perfil editable, salir y borrar con confirmación; token en Keychain/Keystore.

**Por qué.** La cuenta es la identidad que mañana ata boleta ↔ pulsera ↔ monedero ↔ pasaporte A TRAVÉS de eventos: por evento nacería muerta. Sin contraseña porque no hay nada que olvidar ni que robar en un volcado. Sin oráculo de enumeración por ningún lado: 202 idéntico exista o no la cuenta (el camino de emisión ni consulta cuentas), 422 único para código incorrecto/caducado/quemado, y nombre opcional SIEMPRE — exigirlo solo a cuentas nuevas convertiría la validación en el oráculo recién tapiado. El refutador de seguridad encontró el grave que ningún test secuencial ve: el tope de cinco intentos se contaba leyendo, sumando en PHP y guardando el absoluto — bajo concurrencia se perdían incrementos y el OTP se podía forzar; ahora el contador sube atómico en la base y el gasto del código bueno lo decide un DELETE mirando filas afectadas (dos /entrar simultáneos: UNA sesión). La llave del freno por buzón se llavea con hash porque el RateLimiter pasa la llave por htmlentities y josé@/jose@ compartían cubo.

**Garantías.** Los frenos de la cuenta matan al CÓDIGO, jamás a la cuenta: no existe «cuenta bloqueada». El cortacircuitos global de emisión (600/min, 6× el pico legítimo) usa llave CONSTANTE — quien ataca no elige qué cubo llena. El token del asistente no abre POS/KDS/staff ni al revés. El Bearer viaja solo por el camino con sesión; las puertas anónimas nunca lo mandan. Un 401 `sesion_invalida` devuelve a anónimo sin tirar la pantalla, y solo ESE 401 desconecta (uno pelado de un proxy es avería). Borrar la cuenta borra de verdad, mata el código vigente del buzón, y ese método es el dueño de la decisión de anonimizar cuando existan pedidos o saldo.

#### [Documentación] Doc 12: tarjeta guardada con Cybersource, investigado contra la doc oficial
<sub>`(doc 12)`</sub>

**Qué.** Diseño completo de la integración de pago con tarjeta guardada para la app del asistente, investigado en la documentación oficial de Cybersource con tres lentes (TMS, captura móvil, cobro con token). Decidido: captura por **webview con Microform v2** servido por Laravel (los campos sensibles viven en iframes de Cybersource → elegibilidad SAQ A, el alcance PCI mínimo; descartados los SDKs nativos puenteados —sin releases desde 2022— y la captura REST en Dart —criptografía de pago en casa—); estructura TMS de un Customer token por cuenta de asistente con N Payment Instruments; alta de tarjeta y primer cobro en UNA llamada (`TOKEN_CREATE` dentro del authorization, banderas CIT de credencial guardada); compra de dos toques con `customer.id` sin recaptura; `client_ref` mapeado a `clientReferenceInformation.code` con verificación en `/tss/v2/searches` antes de reintentar. El pedido pagado entra al KDS por el canal `mobile` que ya existía — del lado cocina no hay nada nuevo.

**Por qué.** Boletu ya procesa con Cybersource en producción, así que el gate de pasarela es integración, no contratación. El hallazgo urgente salió de rebote: **Flex/Microform v0.11 y v1 mueren el 31 de agosto de 2026** — hay que auditar la boletería web de Boletu ESTE MES, con o sin app. Y quedó separada la lista de lo que la doc no responde y solo el account manager o el adquirente dominicano pueden contestar: TMS habilitado en el MID, DOP como moneda de proceso, CIT sin CVV, 3DS en RD, network tokens y la evidencia de consentimiento.

**Garantías.** El PAN y el CVV no tocan jamás nuestro backend ni se almacenan (la CVN está prohibida por la doc); nosotros solo guardamos ids de token + marca/últimos 4/vencimiento para pintar. El capture context lo genera siempre Laravel, nunca credenciales dentro del binario. `DELETE /cuenta` se extiende a la bóveda borrando cada payment instrument y el customer, sin depender de una cascada no documentada. Ningún cobro se reintenta sin consultar antes por `client_ref`.

#### [Documentación] El código de Boletu desmiente a la doc: cómo opera Cybersource de verdad
<sub>`(doc 12 §0)`</sub>

**Qué.** Análisis de solo lectura del Laravel de producción de Boletu (`BuletuV2`) con tres lentes —pasarelas y SDK, bóveda y cuotas, captura y 3DS— volcado al doc 12 como secciones §0.x que CORRIGEN al diseño hecho solo con documentación. Hallazgos: Boletu usa **Unified Checkout v1**, no Microform; **ya guarda tarjetas en producción** para cobrar cuotas (`payment_plans.tokenized_card_ref`, tokens TMS creados con `TOKEN_CREATE` dentro del authorization); **3DS es obligatorio** porque PortalDOM —el integrador local dominicano— lo exigió, con la cadena Cardinal completa orquestada a mano; y la idempotencia va en dos capas (UUID persistido + header `v-c-idempotency-id` + thin transaction con lock fuera del HTTP).

**Por qué.** La alarma que yo mismo di —«Flex/Microform v0.11 muere el 31 de agosto, auditen ya»— **era falsa**: salía de la documentación, no del código, y Boletu no usa Microform en ninguna parte. Queda anulada por escrito para que nadie actúe sobre ella. Al revés de lo que temía, el análisis respondió con hechos casi todas las preguntas que el doc iba a hacerle al account manager (TMS ✓, DOP ✓, CIT sin CVV ✓, 3DS ✓, sandbox ✓) y dejó una sola de verdad abierta: las banderas de «unscheduled COF», porque Boletu solo tiene certificado `reason: 9` (cuotas con cronograma fijo) y la app cobra montos distintos sin calendario.

**Garantías.** Ocho lecciones ya pagadas en producción quedan escritas para no redescubrirlas: el SDK tiene DOS objetos de config y el host por defecto es sandbox (401 solo en producción); la firma usa `request-target` SIN paréntesis; el transient token cambia de nombre entre `/pts/v2/payments` y `/risk/v1/*`; el indicador comercial cambia de nombre entre respuestas; **`body.status` es el único árbitro** (puede venir `responseCode: 00` con `AUTHORIZED_RISK_DECLINED`); el ancla del encadenado es `networkTransactionId` y la regla es por marca; los strings vacíos se rechazan; y un cobro aprobado sin token se aborta con log crítico. Y lo que NO se copia: `PortalDomDirect` mete PAN y CVV en el servidor (alcance SAQ D) — la app no va por ahí bajo ningún concepto.

### 2026-08-07

#### [Plataforma] El dominio de pagos habla con Cybersource, probado contra el sandbox real
<sub>`(ver git log: dominio Payments)`</sub>

**Qué.** Nace `App\Domains\Payments`: `CybersourceClient` (construye el SDK v0.0.75 desde `config('services.portaldom')`), la acción `CobrarConTarjeta` (authorization + capture en `/pts/v2/payments`, tres modos: transient token con `TOKEN_CREATE`, token guardado, y PAN solo-sandbox), `EstadoDeCobro`/`DesenlaceDeCobro` para leer la respuesta, y `CobroSolicitado`/`ResultadoDeCobro` como objetos de valor. Bloque `portaldom` en `config/services.php` con las mismas claves que Boletu. **Probado contra `apitest.cybersource.com` de verdad**: autenticación viva, cobro con Visa 4111 en DOP → AUTHORIZED con `networkTransactionId`, `TOKEN_CREATE` devolviendo customer + payment instrument, y segundo cobro solo con el token. No se persiste nada todavía: las tablas de tarjeta guardada son el slice siguiente.

**Por qué.** Se replicó a propósito el fallo más caro de Boletu: el SDK tiene DOS objetos de configuración y `ApiClient` arma la URL con `Configuration::getHost()` —cuyo defecto es `apitest`—, no con `MerchantConfiguration::getRunEnvironment()`. Fijar solo el segundo da **401 únicamente en producción**, invisible en pruebas porque allí el defecto coincide. Aquí los dos salen de un único método privado (`configuracionDelSdk()`) para que no se pueda reintroducir olvidándolo en una de las dos rutas; de paso se descubrió que el proxy también se lee de ese objeto y no del de comercio. Se descartó cachear el `ApiClient` con la cabecera de idempotencia: pegaría la llave a las llamadas siguientes del proceso —bajo Octane, entre usuarios distintos—. El `commerceIndicator` de la compra de dos toques se manda como CIT (`type: customer`, `storedCredentialUsed`) y no como MIT, porque el asistente está delante del teléfono y porque la variante «unscheduled COF» sigue sin confirmar con PortalDOM. Y el PAN en claro se acotó a un modo propio que la acción **rechaza** fuera del sandbox: un PAN en este servidor es alcance SAQ D, justo lo que el diseño de captura evita.

**Garantías.** `body.status` es el ÚNICO árbitro y la clasificación vive en el enum, no en el `if` de quien llama: `AUTHORIZED` es lo único que despacha, y un estado que Cybersource añada mañana cae en «no aprobado». Un cobro aprobado sin token habiendo pedido `TOKEN_CREATE`, o sin `networkTransactionId`, es log crítico y excepción — «cobrado pero sin credencial» nunca devuelve 200. `PORTALDOM_ENV=live` fuera de `APP_ENV=production` deja la aplicación sin arrancar, comprobado en dos sitios porque `config:cache` salta el primero. El importe se compone con enteros (`sprintf('%d.%02d')`), sin pasar por float. Ni el token, ni el PAN, ni el CVV, ni el JWT entero llegan al log. Y las pruebas contra el sandbox **se saltan solas sin credenciales**: la suite sigue verde para quien no las tiene.

**Pendiente.** El MID de sandbox **no honra `v-c-idempotency-id`**: medido el 2026-08-07, dos cobros con la misma llave y el mismo cuerpo dieron dos transacciones distintas, con la cabecera demostradamente en el request. Hay que pedir la habilitación a PortalDOM antes de producción; el test queda incompleto con el motivo escrito, no borrado.

#### [Plataforma] «Rechazado» y «no sé si se cobró» dejan de ser lo mismo
<sub>`(ver git log: dominio Payments)`</sub>

**Qué.** Un desenlace nuevo, `DesenlaceDeCobro::Incierto`, con su estado `EstadoDeCobro::SinRespuesta`, para el corte de transporte; `CobrarConTarjeta` separa «el servidor contestó» de «no hubo respuesta» mirando el código HTTP y el cuerpo, no el tipo de la excepción, y el silencio va con `Log::error`. Nace la acción `BuscarCobroPorReferencia` (`POST /tss/v2/searches`) con `ConciliacionDeCobro` y `CobroEncontrado`: el camino de reconciliación que el doc 12 §4 pedía, **probado contra apitest** (el MID de sandbox SÍ tiene la búsqueda habilitada; una referencia inexistente devuelve `totalCount: 0` limpio). Además: el motivo del rechazo se lee de `errorInformation` —donde vive de verdad— y no solo de la raíz; `redactado()` pasa de un `if` por campo a una lista de rutas y tapa también `paymentInstrument.id`; los seguros de entorno miran `PORTALDOM_API_HOST` y no solo la etiqueta; `CobroSolicitado` rechaza credenciales en blanco; las banderas de `authorizationOptions.initiator` se acumulan en vez de pisarse; y `tieneToken()` exige LAS DOS piezas.

**Por qué.** El SDK envuelve TODO en `ApiException` —incluido el curl que ni conecta, que lanza `new ApiException($mensaje, 0, [], null)`—, así que el `catch (ApiException)` se quedaba también los timeouts y los devolvía como un resultado ordinario con desenlace `error`: sin log, sin excepción, e indistinguible de un INVALID_REQUEST. En pagos esa es LA distinción: un rechazo significa «no se cobró, reintenta tranquilo» y un corte significa «puede que la tarjeta esté cobrada». Con el MID sin idempotencia, ese reintento a ciegas es un doble cobro real. La rama `catch (Throwable)` que el comentario vendía como la red de seguridad no se alcanzaba nunca para ese caso. Se descartó distinguirlos por el tipo de la excepción (no se puede) y se descartó lanzar en vez de devolver: el llamador necesita el desenlace para decidir, y lanzar lo empujaría a tratar el corte como un fallo de programación. En la búsqueda se descartó devolver una lista pelada: con `[] === $cobros` la lectura natural es «no se cobró, reintenta», y es FALSA durante los primeros segundos —medido: a 0,3 s la búsqueda devolvía 0 y a 4,6 s ya devolvía la transacción—, así que la decisión vive en `sePuedeReintentar($segundos)`, que exige que haya pasado el indexado. Y `paymentInstrument.id` se escribía entero en el log mientras su hermano `customer.id` sí se truncaba, en el mismo objeto: no fue una decisión, fue un olvido, y la forma de que no se repita es que las rutas estén en una lista y no en un `if` que hay que acordarse de escribir.

**Garantías.** Un fallo de transporte NUNCA sale como rechazo: `esRechazado()`, `esAprobado()` y `esPendiente()` contestan que no, `esIncierto()` que sí, y queda una línea `Log::error`. Ante lo incierto no se reintenta sin conciliar: solo `ConciliacionDeCobro::sePuedeReintentar()` lo autoriza, y exige a la vez que no haya rastro y que haya pasado el indexado; si la búsqueda no se puede hacer, revienta con `busqueda_no_disponible` en vez de mentir con un cero. **Ninguna credencial sale entera al log** —ni `customer.id`, ni `paymentInstrument.id`, ni `instrumentIdentifier.id`, ni PAN, ni CVV, ni JWT—, y el log propio del SDK se apaga explícitamente porque escribe el cuerpo entero fuera del alcance de `redactado()`. `PORTALDOM_ENV` y `PORTALDOM_API_HOST` no pueden contradecirse: un host de producción fuera de `APP_ENV=production` no arranca, y `esSandbox()` responde con el host, que es lo que decide a dónde va el dinero. Una credencial en blanco no viaja. Y un cobro aprobado con media credencial es incidente, no éxito.

**Pendiente.** El valor de la clave 27 de la MDD para el caso tokenizado (`TOKENIZATION SI`) sigue siendo **deducción nuestra, no dato confirmado**: Boletu manda siempre `NO` porque nunca tokeniza. Queda escrito como tal en el código. Hay que confirmarlo con PortalDOM, junto con que el MID de producción tenga habilitada la búsqueda de transacciones.

#### [Plataforma] El cimiento de pagos: Cybersource probado contra el sandbox de verdad
<sub>`(dominio Payments)` · ADR pendiente</sub>

**Qué.** Dominio `App\Domains\Payments` con el cliente de Cybersource (vía PortalDOM, el integrador local dominicano) y la acción de cobro, **verificado contra apitest.cybersource.com**: autentica, cobra en DOP, tokeniza con `TOKEN_CREATE` y vuelve a cobrar usando SOLO el token. Más `BuscarCobroPorReferencia` sobre `/tss/v2/searches` para reconciliar antes de reintentar. SDK oficial desde packagist (v0.0.75). 104 tests entre los que no tocan red y los que sí (`--group=cybersource`, se saltan solos sin credenciales).

**Por qué.** Boletu ya procesa con Cybersource en producción, así que este cimiento copia sus lecciones en vez de redescubrirlas — el doble objeto de configuración del SDK (cuyo host por defecto es sandbox, y omitirlo da un 401 que SOLO aparece en producción), `body.status` como único árbitro (un `responseCode: "00"` puede venir con `AUTHORIZED_RISK_DECLINED`), y el aborto ruidoso si un cobro aprueba sin devolver la credencial. Lo que la lente adversarial añadió es la distinción que ninguna doc subraya: **«me dijeron que no» y «no sé si se cobró» no pueden ser el mismo desenlace**. Un rechazo invita a reintentar; un corte significa que la tarjeta puede estar cobrada, y en un festival con mala señal eso pasa. Se separan por código HTTP y presencia de cuerpo —no por el tipo de excepción, que el SDK unifica—, incluido el caso más traicionero: un 2xx cuyo cuerpo llegó a medias, que Cybersource ya contó como transacción.

**Garantías.** Ninguna credencial entera llega a un log: `redactado()` es una lista de rutas (no un `if` por campo, que fue justo el olvido que dejó un token de cobro en claro) y el log propio del SDK —que escribe el cuerpo entero a otro fichero— se apaga explícitamente. Los seguros de entorno miran el HOST y no la etiqueta, porque el host es lo que decide a dónde va el dinero: `PORTALDOM_ENV=test` con host de producción no arranca. La llamada del dinero lleva plazo (el SDK trae «espera para siempre»), porque un proceso colgado dentro del cobro muere sin dejar resultado ni rastro. El modo PAN existe solo para el sandbox y la acción lo rechaza fuera de él.

**Abierto y medido:** el MID de sandbox **ignora `v-c-idempotency-id`** — dos llamadas con la misma llave dieron dos transacciones aprobadas, con la cabecera demostradamente en el request. Hay que pedirle la habilitación a PortalDOM antes de producción; sin ella, un reintento por mala señal es un doble cobro real. Y la búsqueda de reconciliación tarda ~5 s en indexar: preguntar justo tras el corte devuelve cero y eso invita al error, así que la autorización para reintentar exige que además haya pasado ese plazo.

---

## Índice de garantías transversales

> Las reglas que atraviesan todo el sistema. Romper una de estas rompe algo lejos
> del sitio donde escribiste. Si una te estorba, no la esquives: busca su hito
> arriba y entiende qué se rompía sin ella.

### Aislamiento (multi-tenancy de dos ejes)

- **`TenantScope` falla CERRADO**: sin contexto de tenant no se ve nada (`where 1 = 0`).
  `tenant_id` es NOT NULL, inmutable y nunca en `fillable`; se rellena del contexto.
- **`VendorScope` falla ABIERTO**: solo filtra cuando hay comercio en contexto, porque
  el organizador necesita ver el consolidado del evento. **Consecuencia:** todo endpoint
  que fije contexto por URL necesita su propio backstop explícito
  (`abort_unless(VendorContext::check())`), y las tablas nuevas del mundo evento llevan
  `vendor_id` NOT NULL como red de la base.
- `insert()` y `upsert()` están prohibidos en modelos scopeados: saltan los eventos de
  Eloquent y pueden pisar la fila de otro tenant.
- Cruzar de tenant nunca es accidental: `runAs()` para escribir, `withoutTenancy()` para leer.

### El dinero

- **Todo en centavos enteros.** Pesos con decimales solo en pantalla.
- **La propina legal (10%) viaja DENTRO de `total_cents`.** Toda cifra agregada sale de
  `SalesSummary` o de los desgloses — **nunca** de `sum('total_cents')`.
- **Costo desconocido es `null`, jamás `0`**: un insumo que no resuelve mostraría margen
  100% en verde para un producto vendido bajo costo.
- Los cortes de día usan `config('app.business_timezone')` (RD), no UTC.

### El cobro con tarjeta (Cybersource / PortalDOM)

- **`body.status` es el ÚNICO árbitro.** `AUTHORIZED` es lo único que despacha. NO se mira
  `responseCode` ni `approvalCode`: puede venir `"00"` con código de aprobación válido y
  `status: AUTHORIZED_RISK_DECLINED`, que es un RECHAZO. La clasificación vive en
  `EstadoDeCobro`, no en el `if` de quien llama, y un estado desconocido nunca aprueba.
- **Cobrado sin credencial es un incidente, no un éxito.** Un cobro aprobado sin token
  habiendo pedido `TOKEN_CREATE`, o sin `processorInformation.networkTransactionId` (el
  ancla del encadenado, NO el `id` de la transacción), es log crítico y excepción.
- **El SDK tiene DOS objetos de configuración** y hay que fijar el host en los dos:
  `ApiClient` arma la URL con `Configuration::getHost()`, cuyo defecto es `apitest`.
  Fijar solo `MerchantConfiguration::setRunEnvironment()` da **401 solo en producción**.
  En este proyecto los dos salen de `CybersourceClient::configuracionDelSdk()`.
- **El cliente con `v-c-idempotency-id` es EFÍMERO y nunca se cachea**: la cabecera vive
  en el `Configuration`, así que pegarla al compartido la deja en todas las llamadas
  siguientes del proceso — bajo Octane, entre usuarios distintos.
- **El PAN nunca toca este servidor en producción** (alcance SAQ D): la captura vive en
  la webview de Cybersource y aquí solo entra el `transientTokenJwt`. El modo con PAN
  existe solo para el sandbox y la acción lo rechaza contra cualquier otro host.
- **`PORTALDOM_ENV=live` fuera de `APP_ENV=production` no arranca**, y tampoco arranca un
  `PORTALDOM_API_HOST` de producción fuera de producción: el host es la variable que
  decide a dónde va el dinero, la etiqueta es solo una etiqueta. Las dos no pueden
  contradecirse. Comprobado en `config/services.php` y otra vez al construir el cliente,
  porque `config:cache` salta el primero.
- **«Rechazado» y «no sé si se cobró» NO son lo mismo.** Un corte de transporte sale con
  desenlace `Incierto` (`EstadoDeCobro::SinRespuesta`) y `Log::error`, nunca como un
  rechazo: un rechazo invita a reintentar y un corte puede ser una tarjeta ya cobrada.
  La distinción se hace por código HTTP y cuerpo, NO por el tipo de la excepción — el SDK
  envuelve en `ApiException` tanto un 400 con cuerpo como un curl que ni conectó.
- **Ante lo incierto se concilia antes de reintentar**, con `BuscarCobroPorReferencia`
  (`/tss/v2/searches` por `clientReferenceInformation.code`). Un `totalCount: 0` NO
  autoriza el reintento por sí solo: la búsqueda tarda ~5 s en indexar, así que la
  autorización la da `ConciliacionDeCobro::sePuedeReintentar($segundos)`. Si la búsqueda
  no se puede hacer, revienta: «no existe» y «no pude mirar» son decisiones opuestas.
- **Ninguna credencial entera en el log.** Ni `customer.id`, ni `paymentInstrument.id`,
  ni `instrumentIdentifier.id`, ni PAN, ni CVV, ni el JWT. Las rutas a tapar viven en una
  lista dentro de `redactado()`, no en un `if` por campo: añadir una credencial al cuerpo
  obliga a añadirla ahí. El log propio del SDK va apagado explícitamente — escribe el
  cuerpo entero a su fichero, fuera del alcance de `redactado()` y de `paraLog()`.

### La historia no se reescribe

- **Una venta pagada o anulada es inmutable**: `Order::updating` lanza según el estado
  ORIGINAL, y `OrderLine::updating` lanza siempre.
- Los precios, el ITBIS, la comisión y el área de despacho se **congelan en la línea**
  al vender: el catálogo puede cambiar después sin reescribir el pasado.
- **Los tenants y los productos no se borran**, se suspenden o desactivan: la historia
  fiscal y las ventas antiguas tienen que sobrevivir.
- Lo liquidado se blinda: un estado de cuenta cerrado no admite ventas nuevas hacia atrás.

### La cocina

- **PENDIENTE es la AUSENCIA de fila** en `kitchen_tickets`. El tablero es
  `orders LEFT JOIN kitchen_tickets`, y la comanda es un hecho nuevo, no una edición
  de la venta (que es inmutable).
- Las transiciones tienen **control optimista por ESTADO**, no por reloj: un choque
  devuelve 409 con la fila vigente dentro, así que reintentar es seguro.
- Los tiempos se miden con **mediana y p90, nunca media**, y los percentiles se calculan
  en PHP (MySQL y SQLite difieren).
- El **retraso de red** se mide aparte del tiempo de cocina: los informes no pueden
  culpar al cocinero por el wifi.

### Las puertas (una por audiencia — ADR-007)

- `saas-admin` · `event-panel` · `event-vendor` · `event-pos` · `business` · KDS.
  Cada audiencia entra por su puerta; el rebote lo decide `HomeForUser`.
- Los tokens de larga vida **revalidan todo en cada petición** (permisos, estado de la
  cuenta, vigencia): un permiso retirado tiene que morder ya.
- Trampas de Sanctum documentadas y esquivadas: `guard => ['web']` (una sesión web
  autenticaría a esa persona) y `sanctum:prune-expired` (borra por `created_at`,
  ignorando `expires_at`).

### Frenos y disponibilidad

- **Un freno nunca puede dejar fuera a quien hace las cosas bien.** Tres intentos
  cayeron por lo mismo: un contador que sube quien ataca, sobre algo que él elige,
  cerrándole la puerta a un tercero. Si un freno puede negar un acierto, está mal.
- Un freno que **encarece o ralentiza** al que falla, sin poder negar al que acierta,
  sí es aceptable (la penitencia del alta del KDS).
- **Deuda abierta:** con `trustProxies(at: '*')` la IP la escribe quien llama, así que
  todo límite por IP es esquivable. Cerrarlo exige acotar `at:` a los rangos del borde
  (ngrok / Railway) — decisión de despliegue pendiente.

### La app del asistente (puerta `event-app`)

- **El binario no sabe de ningún festival.** Lleva a qué servidor preguntar y qué
  evento es; marca, módulos, orden y textos vienen del manifiesto. Cambiar la app de
  un evento no puede exigir recompilar ni pasar por una tienda.
- **Un módulo desconocido se ignora en silencio**, y uno sin `activo` cuenta apagado.
  Los dos lados fallan cerrado, y el servidor puede encender algo nuevo antes de que
  todos los teléfonos lo entiendan.
- **El 404 significa «este código no es de nadie»**, nunca «nadie configuró todavía».
  Un evento sin manifiesto sirve 200 de fábrica; uno cerrado o liquidado también, con
  su `estado` — apagar la puerta al cerrar convertiría en error miles de apps
  instaladas el lunes.
- **Los frenos de la cuenta matan al código, jamás a la cuenta.** No existe
  «cuenta bloqueada»: cinco fallos queman el código y se pide otro. Y ningún
  endpoint de identidad tiene oráculo de enumeración — ni por cuerpo, ni por
  código de estado, ni por tiempo.
- **La sesión es un accesorio, no el suelo.** La app funciona entera anónima;
  un 401 `sesion_invalida` devuelve a anónimo sin tirar la pantalla, y solo ese
  401 desconecta.
- **Se cachea el cuerpo, jamás la puerta.** Sin token que revocar, revalidar en cada
  petición es la única revocación que existe. Y en esa caché solo viajan **datos
  planos**: `serializable_classes => false` corrompe cualquier objeto, y los tests
  corren con store `array`, así que la suite no se enteraría.

### El cliente en el campo

- **Cascarón tonto, cerebro en el servidor**: el APK y el POS no deciden reglas de
  negocio; se cambian desplegando, no reinstalando veinte aparatos.
- **Offline-first en el POS**: la venta se guarda local y sincroniza; `client_ref` da
  idempotencia y el reintento nunca duplica.
- Una pantalla **nunca puede mentir sobre estar viva**: si no hay datos frescos, lo dice
  (la franja `aCiegas` del KDS, el vigía del APK). Una pantalla congelada que parece viva
  es el peor fallo posible en una cocina.

---

## Glosario

> Términos propios del proyecto, en orden alfabético. Entendibles sin leer código.

- **aCiegas.** La alarma del KDS cuando la pantalla lleva demasiado sin una respuesta buena del servidor: la señal de que las comandas en pantalla pueden no ser ciertas. Se enciende desde el arranque, sin exigir un éxito previo.
- **alta (enrolar).** El proceso de dar de alta la tablet contra el servidor: código del comercio y PIN del puesto en la app web (POST /api/kds/enrolar). En el cascarón, la pantalla del alta con la dirección manda sobre todo: nada puede taparla salvo su propio botón de guardar.
- **arqueo.** Conteo de caja al cerrar un turno: efectivo esperado (fondo + ventas − reembolsos en efectivo) contra contado, con la diferencia imputada al cajero. Los reembolsos salen de la caja abierta de quien devuelve, por eso restan el día en que salieron de la gaveta.
- **bandeja de revisión.** Donde aterrizan las ventas cuyo sync falló con un error definitivo (4xx como client_ref_reused u order_voided): requieren decisión humana — reintentar o descartar — en vez de reintentarse para siempre.
- **bandeja de salida (outbox).** Cola local en el dispositivo POS donde cae cada venta cobrada sin señal; se sincroniza con el servidor al volver la red. El cierre de caja y el logout exigen que esté vacía.
- **bandeja del dispositivo.** Panel del POS que lista las ventas del aparato con su estado de sincronización (por sincronizar, en revisión, descartada) y, desde la numeración, también las últimas cobradas con su número real.
- **caja (cash session).** Sesión de caja del POS: se abre con un fondo inicial y se cierra contra lo contado. El servidor garantiza una sola caja abierta por unidad.
- **cartel (pantalla de error).** La pantalla de error en español del cascarón, con la dirección del servidor y botón de reintentar, que sustituye al dinosaurio de Chrome. Solo aparece ante el fallo de la página principal y desde agosto se cura sola con reintentos.
- **cascarón.** La app Android del KDS: un WebView a pantalla completa sin lógica de negocio. Todo lo de cocina vive en la web que sirve Laravel; el cascarón solo aporta lo que un navegador no puede (pantalla encendida, quiosco, audio sin gesto, batería, identidad).
- **client_ref.** Referencia generada por el POS offline que hace idempotente la sincronización: aunque una venta se reenvíe mil veces, produce una sola orden, un solo cobro y un solo descuento de stock. Acotada a la unidad operativa.
- **comanda.** La orden de preparación que va a cocina o barra. Su destino lo decide la clasificación de la categoría del producto: Alimentos salen de cocina, Bebidas de barra.
- **comanda (kitchen ticket).** El encargo de preparar lo vendido, por área (barra o cocina): un hecho nuevo y mutable que referencia la venta inmutable, jamás una edición de ella. Pendiente es la ausencia de fila; la clave es el par (orden, área).
- **comanda zombi.** Tarjeta que el tablero pinta pero que ningún toque puede avanzar (contestaba 422): nacía de que la regla del área por defecto estaba repetida y el punto que decidía el avance no la conocía.
- **Comercio (Vendor).** Negocio participante en los eventos de un organizador (con RNC propio, porque factura lo que vende). Es el segundo nivel de aislamiento: su catálogo, insumos, puntos de venta, usuarios y ventas son suyos; el organizador ve el consolidado pero no opera.
- **comercio (vendor).** Negocio tercero e independiente dentro de un evento: conserva su catálogo, inventario, equipo e histórico entre ediciones. El organizador lo invita a cada evento con una comisión pactada y ve su rendimiento, pero no opera por él.
- **commission_bps.** Comisión del organizador del evento en basis points, pactada en la participación de cada comercio y congelada en la orden al vender. Es la base del reporte del organizador.
- **congelar (la venta).** Disciplina de inmutabilidad: la orden guarda copia de precio, exención, ITBIS por línea, comisión (bps) y modalidad de ITBIS vigentes al cobrar. Cambiar la regla después no reescribe lo cobrado; los modelos Order y Refund además se defienden solos contra updates acotados por clave.
- **congelar (números al vender).** Copiar a la orden, en el momento del cobro, los valores fiscales y de comisión vigentes (itbis_cents, commission_bps): cambios posteriores de catálogo o de contrato jamás reescriben ventas pasadas.
- **despacho (dispatch).** A qué área va cada línea de venta: cocina o barra. Se congela en order_lines.dispatch AL VENDER, porque la categoría es mutable y recategorizar no puede reescribir la historia. Si es NULL, decide DispatchArea::porDefecto().
- **device_identity.** Etiqueta ANDROID_ID de la tablet para reutilizar su fila al recolgarse. Jamás es credencial: se lee solo DESPUÉS de que código de comercio y PIN hayan pasado, y es inmutable en la fila.
- **dispatch (área de despacho).** A qué área va cada línea vendida (cocina o barra). Se congela en order_lines al vender, porque leerlo de la categoría —mutable— reescribiría la historia al recategorizar.
- **entrada única (/entrar).** La única pantalla de login. HomeForUser decide la puerta de cada quien por capacidades, no por nombre de rol; quien solo opera caja va al POS, nunca a un 403.
- **Escandallo.** La receta de un producto: qué insumos consume y en qué cantidad. De él salen el costo y el margen del producto, alimentados en vivo por el costo promedio ponderado del inventario.
- **escandallo.** La receta de un producto tipo receta: la lista de insumos con cantidad y unidad que se descuentan del inventario al venderlo.
- **Fail-closed.** Principio rector del aislamiento: ante ausencia de contexto o ante un dato que no resuelve, el sistema niega o muestra vacío, nunca concede ni inventa. Sin contexto de tenant no se ve nada; un costo desconocido es null y "—", jamás cero.
- **fail-closed.** Ante falta de permiso, no mostrar nada: el dashboard sin reports.view_tenant abre sin números. Contrasta con los defaults de negocio, que ante duda cobran (un producto de catálogo viejo cuenta como gravado).
- **fail-closed / fail-open.** Cómo falla una protección: cerrada (sin contexto no devuelve nada, como TenantScope) o abierta (sin contexto no filtra, como VendorScope). El proyecto exige puertas fail-closed y trata cada pieza fail-open como un peligro a rodear explícitamente.
- **flavor.** Variante de build de Flutter que solo carga la identidad de tienda de cada evento (icono, nombre, bundle id), obligada por la regla Apple 4.2.6: cada evento publica bajo su propia cuenta de developer.
- **guard de orígenes.** Las dos puertas que impiden que otra página vea el puente: shouldOverrideUrlLoading (navegaciones) y shouldInterceptRequest (subrecursos). Ambas comparan el origen desmontado — esquema, host y puerto — contra el servidor configurado, nunca prefijos de texto.
- **HomeForUser.** La única pieza que decide a qué puerta va cada usuario tras el login (staff, cuenta, comercio, caja). Login, middlewares, raíz y enlaces la comparten para no contradecirse jamás.
- **identidad del aparato.** El ANDROID_ID de la tablet, enviado solo en el alta como device_identity para no duplicar filas en kds_devices. No es una credencial (el alta sigue exigiendo código y PIN) y está acotado por la clave de firma del APK, no por el paquete.
- **insumo.** Materia prima del inventario de un comercio. Un producto Simple puede vincularse a UN insumo (vende 1, descuenta 1); uno de receta descuenta según su escandallo.
- **ITBIS.** Impuesto dominicano del 18 % incluido en el precio. Cada producto declara si es gravado o exento; el desglose se calcula línea a línea y se congela en order_lines.itbis_cents al vender.
- **ITBIS hacia adentro.** El impuesto dominicano (18%) va incluido en el precio al público y se desglosa hacia adentro en la venta, no se suma encima.
- **Jornada de caja (CashSession).** Sesión de caja por unidad operativa: una sola abierta a la vez, cierre contra lo contado con esperado (fondo más efectivo cobrado) y diferencia con signo. Una caja con órdenes abiertas no se cierra.
- **KDS.** Kitchen Display System: la pantalla de comandas que la tablet de un puesto muestra con tres estados (pendiente, en proceso, lista). La tablet entra con código de comercio + PIN del puesto, sin ser ningún usuario, con token propio revocable (no Sanctum).
- **KitchenBoard.** La consulta única del tablero de comandas (orders LEFT JOIN kitchen_tickets). Alimenta a la vez la tablet del cocinero y el tablero en vivo del organizador para que jamás diverjan.
- **Libro mayor de stock (StockLedger).** Única puerta de escritura del inventario: cada compra, ajuste, merma o traslado es un movimiento inmutable más una proyección de existencias, en una transacción con locks. Un error no se edita: se corrige con un ajuste que deja rastro.
- **liquidación.** El estado de cuenta final de un festival: cuánta comisión le toca al organizador por cada comercio. Borrador vivo durante el evento; cifras guardadas e intocables al liquidar; después no hay reembolsos.
- **Los dos mundos.** Los dos tipos de cuenta del SaaS: negocio (bar/restaurante con sucursales) y organizador (festivales con eventos y comercios participantes). Son mundos cerrados que no comparten datos, y la separación es estructural: cada mundo tiene sus propias clases (STI sobre una sola tabla), sin condicionales de mundo en el código.
- **los tres tiempos.** Los relojes de cocina: espera del cliente (pago→listo, se congela), en cola (llegada→empezada) y preparando (empezada→lista). Más «en el pase» (hecha sin entregar, sigue vivo) y el retraso de sincronización como cuarto tramo separado.
- **manifiesto (white-label).** Configuración servida por el backend que arma la app del asistente en runtime: qué módulos van, en qué orden, colores, textos y arte. El patrón del KDS elevado a arquitectura: cascarón tonto, cerebro en el servidor — cambiar la app no recompila ni pasa por revisión de tienda.
- **modalidad de ITBIS.** Regla fiscal de si el 18 % va dentro del precio de carta (incluido) o se suma al cobrar (se suma). Vive en la cuenta con override por comercio (null = hereda); toda la matemática la concentra el enum ItbisMode y la venta la congela al cobrarse.
- **mundo (eventos / negocio).** Las dos modalidades del SaaS: eventos (organizador de festival con comercios terceros, comisión por venta) y negocio (bar/restaurante independiente con sucursales). El dominio está partido en EventManagement y Business, y las puertas siguen esa partición.
- **mundo / modalidad.** Cada uno de los dos universos cerrados de la plataforma: eventos (cuenta de organizador) y negocio (bar/restaurante permanente). No comparten datos jamás; solo comparten código.
- **NCF.** Número de Comprobante Fiscal dominicano (DGII). No existe todavía en el sistema: por eso el recibo impreso advierte que no es un comprobante fiscal válido para crédito.
- **NCF / e-CF.** Folio fiscal autorizado por DGII para cada venta (y su versión electrónica de la Ley 32-23). Decidido: bloques de folios reservados por terminal para foliar offline sin colisiones; aún sin construir.
- **número de orden.** Identificador legible de la venta (P0001): serie única por comercio (por cuenta en el mundo negocio), con letra de canal que es etiqueta y no serie (P pos, M móvil, W web). El par (cuenta, comercio, número) es único en base de datos; el UUID queda como dato de soporte.
- **outbox.** Cola local del POS offline (Dexie/IndexedDB) donde las ventas esperan sincronización con el servidor. El UUID de cada venta es su referencia de idempotencia: reenviar no duplica. Es parte del motor offline único que comparten /pos y /event-pos.
- **outbox (bandeja de salida).** Cola local del POS en IndexedDB donde cada venta espera su sincronización, con estados visibles al cajero. El POS emite hechos; el servidor los absorbe.
- **panel-theme.** La plantilla Preline Pro comprada: su HTML vive en resources/panel-theme y sus assets en public/panel-theme, ambos fuera de git; se restauran desde el ZIP licenciado y sin ellos las vistas caen a un layout simple vía $panelLayout.
- **Participación (EventVendor).** La relación comercio-evento: qué comercio va a qué festival y con qué comisión. Un mismo comercio puede ir a dos festivales con condiciones distintas; de la participación colgará la liquidación de cada edición.
- **penitencia.** Estado por comercio ante ráfagas de PINs errados en el alta del KDS: no bloquea ninguna puerta, solo deja de gastar bcrypt en respuestas que ya se saben negativas. Un PIN correcto entra exactamente igual con ella puesta.
- **Plantilla de rol (role_template).** Definición de un rol en base de datos, administrada por el superadmin y propagada a todas las cuentas. Los permisos siguen fijos en código (un permiso sin código que lo compruebe no protege nada); lo componible son los roles. Cada plantilla declara su alcance: equipo de cuenta, personal de comercio o ambos.
- **Propina legal.** El 10% de ley (República Dominicana), opcional en la orden, calculado sobre la base SIN ITBIS (calcularla sobre el total con ITBIS cobraba un 11.8% efectivo — bug corregido en el blindaje de ventas).
- **propina legal.** El 10 % de servicio obligatorio en RD. Se calcula sobre la base sin impuesto (en productos exentos, sobre el precio completo) y viaja DENTRO de total_cents — no es venta.
- **puente (PuenteDelAparato / window.PayroneKds).** El único punto donde la web habla con lo nativo, vía addJavascriptInterface y de solo lectura: bateria() e identidad(). Cadena vacía significa «el sistema no lo da» y se respeta; el puente no decide nada, los criterios viven en el servidor.
- **puerta.** Entrada web propia de cada modalidad/audiencia del sistema (ADR-007): /saas-admin la plataforma, /event-panel el organizador, /event-vendor el comercio del evento, /event-pos y /pos las cajas, /business el bar independiente. Cada una con su middleware fail-closed y su matriz de rebotes.
- **puerta (con nombre).** Patrón del proyecto para tocar filas protegidas por el guard de inmutabilidad: nunca se debilita el guard, se le abre un método explícito (saveTransition() en KitchenTicket, guardarReenrolamiento() en la tablet) que sigue bloqueando las columnas críticas.
- **Puerta por capacidad.** El acceso a cada punto de entrada (panel, POS, API) se decide por lo que el usuario puede hacer (sus permisos efectivos), nunca por el nombre de su rol: un clon del cajero con otro nombre queda igual de fuera del panel.
- **puesto.** Barra o cocina de un comercio dentro de un evento; es una unidad operativa. Su equivalente en el mundo negocio es la sucursal.
- **puesto (unidad operativa).** El punto físico de un comercio dentro del evento (una barra, una cocina). Tiene su propio PIN de alta para tabletas y declara su área de despacho por defecto.
- **puesto de venta.** El punto físico donde un comercio vende dentro de un evento; nace adscrito a un comercio y a un evento concreto.
- **reloj de frescura.** Indicador de la app web que cuenta cuánto hace que el tablero no consigue datos frescos del servidor. Por eso un sondeo fallido no debe tapar la pantalla: la propia web ya lo cuenta.
- **retraso de sincronización.** El tramo entre el cobro offline en la tablet del POS y la llegada de la venta al servidor. Se mide aparte para que el informe no acuse a la cocina de un problema de wifi.
- **retroceso.** El backoff del reintento automático del cartel: 5, 10, 20, 40 segundos y techo de un minuto. El botón manual lo borra, y también se borra al volver un tablero de verdad; el plazo se guarda como instante, no como cuenta atrás.
- **Revisión adversarial.** Patrón de trabajo del proyecto: tras construir cada dominio, decenas de agentes lo atacan y los hallazgos confirmados se corrigen en un commit de "blindaje" que es parte del hito, no un parche posterior.
- **revisión adversarial.** Práctica sistemática del proyecto: tras cada feature, una pasada que intenta romperla (a veces cinco lentes en paralelo con un verificador por hallazgo que ejecuta tests). Sus «remates» son commits propios y ahí viven los porqués más valiosos.
- **SalesSummary.** Pieza de lectura que produce las cifras de ventas correctas: neta de reembolsos y con la propina legal separada. Es la vía obligada para reportes de dinero.
- **SalesSummary / NetSales.** Las dos consultas canónicas de dinero. SalesSummary separa cobrado, devuelto, propina y venta (identidad: ventas + propina + devuelto = cobrado) cortando por paid_at; NetSales corta por el día de la devolución para cuadrar con el arqueo. Nunca sum(total_cents).
- **siete toques.** El gesto para volver a la pantalla de la dirección del servidor: siete toques en la esquina superior izquierda. No es un secreto sino un seguro contra pulsaciones accidentales con las manos llenas.
- **sonda.** La pregunta del vigía («¿cuántos hijos tiene #kds?»). Es la única autoridad sobre si el tablero cargó de verdad, en lugar de los callbacks del WebView como onPageFinished, que fallan por los dos lados.
- **sondeo.** El polling del tablero contra el servidor, autenticado por token y abaratado con If-None-Match/304. La batería viaja en sus cabeceras (X-Kds-Bateria, X-Kds-Cargando), nunca en la URL, para no romper la caché.
- **sondeo con ETag.** El KDS pide sus comandas cada 3 s con If-None-Match; si nada cambió el servidor contesta 304 sin cuerpo. El ETag excluye server_time (si no, jamás habría 304) y datos como la batería viajan en cabecera para no romperlo.
- **Techo de concesión.** Nadie puede asignar un rol cuyos permisos superen los suyos propios. Se mide por capacidad (conjunto de permisos), no por nombre de rol, para resistir roles nuevos.
- **tenant_id.** Primer eje de aislamiento: la cuenta. Toda tabla de negocio lo lleva, con global scope automático fail-closed y suite de aislamiento en CI como contrato.
- **TenantContext / tenant_id.** El aislamiento multi-tenant por columna compartida: tenant_id es inmutable, nunca asignable en masa, se rellena siempre del contexto de la petición. Cruzar de tenant es siempre explícito (runAs para escribir, withoutTenancy para leer).
- **unidad operativa.** El concepto que unifica sucursal y puesto de evento: todo lo transaccional (ventas, cajas, stock, personal en turno) cuelga de una, y por eso POS, inventario y reportería se construyen una sola vez para los dos mundos.
- **Unidad operativa (OperatingUnit).** Abstracción que unifica sucursal (de un negocio) y punto de venta (de un evento): todo lo transaccional (ventas, stock, cajas) cuelga de ella, así el POS y el inventario se construyen una sola vez para ambos mundos.
- **usuario del POS (username).** Identidad corta (máx 30, minúsculas, única en la plataforma) que un cajero teclea en el terminal. El correo sigue siendo la identidad de los paneles web.
- **vendor_id / VendorScope.** Segundo eje de aislamiento: el comercio dentro de la cuenta de organizador (nulo en el mundo negocio). Su scope falla ABIERTO — sin comercio en contexto no filtra nada—, por lo que las puertas limpian o verifican el contexto y las tablas del KDS lo llevan NOT NULL como backstop.
- **VendorContext::runAs.** Mecanismo por el que el equipo de la cuenta opera «como el comercio»: las filas nacen con el vendor_id correcto, lo ajeno es 404 por scope y los guards de aislamiento del dominio siguen aplicando.
- **venta en revisión.** Estado al que va una venta cuando el total que cobró el dispositivo no coincide con el que el servidor calculó (por ejemplo, catálogo o regla fiscal desactualizados), con su explicación adjunta, en vez de pasar como buena.
- **vigía.** Watchdog del cascarón que cada 3 segundos cuenta los hijos del contenedor #kds. Si en 12 segundos no hay ninguno recarga, y a la tercera saca la pantalla de error. Existe contra la «pantalla negra que parece viva»: HTML cargado pero bundle colgado.
- **índice ciego.** HMAC del PIN llaveado con la APP_KEY y salado por comercio, que localiza el puesto candidato en el alta sin autenticarlo: deja un solo bcrypt por intento. Se escribe al emitir el PIN.
