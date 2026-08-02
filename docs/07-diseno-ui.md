# 07 — Diseño de UI y Paneles

**Estado:** vigente — describe lo construido a 2026-08-02. Sustituye la propuesta del 2026-07-26.

## Principio rector

Una puerta por audiencia y por modalidad, cada una con su chrome y su guard
(ADR-007). No hay un panel que se adapte con condicionales: si dos mundos
tienen reglas distintas, tienen pantallas distintas.

| Puerta | Usuario | Stack | Personalidad |
|---|---|---|---|
| `/saas-admin` | Equipo de la plataforma | Filament | Funcional, tablas, sin marca |
| `/event-panel` | Organizador del evento | Blade + Preline Pro | Claro, denso en datos |
| `/event-vendor` | Encargado del comercio | Blade + Preline | Claro, una sola casa, sin sidebar |
| `/event-pos` · `/pos` | Cajero | PWA Vue offline-first | Oscuro, plano, táctil |
| `/business` | Bar/restaurante propio | Blade + Preline | Pendiente de construir |

`/app` (el panel Filament de cuentas) sigue montado, pero está en vía muerta:
es el «panel clásico» del que se migra. Cuando `/event-panel` y `/event-vendor`
alcancen paridad, se apaga.

## Base visual de las puertas web: Preline Pro

Preline Pro 4.2.0 está comprado y es la base de `/event-panel`. Como es código
licenciado, **vive fuera de git**: `.gitignore` excluye `/public/panel-theme` y
`/resources/panel-theme`, y se restaura desde el ZIP de la compra.

Para que un clon fresco y la suite de tests funcionen igual, `AppServiceProvider`
detecta si el tema está presente y comparte la variable `$panelLayout`:

- con tema → `paneltheme::layout` (chrome Pro, ApexCharts, claro/oscuro);
- sin tema → `event-panel.layout`, un layout propio con Preline gratis.

Por eso **ninguna vista nombra un layout fijo**: todas hacen `@extends($panelLayout)`.
Sin el tema el panel se ve más pobre, pero nada se rompe.

Tres cosas que hay que saber antes de tocar el CSS:

- `resources/css/panel.css` importa `preline/variants.css` **por ruta relativa**:
  el paquete no lo expone en su mapa de `exports`.
- Declara `@source '.../preline/dist/*.js'` para que Tailwind vea las clases que
  viven dentro de los plugins.
- Sube el telón de los modales a `z-70`: el sidebar del tema está en z-60 y los
  modales en z-80.

Y `resources/js/panel.js` llama a `HSStaticMethods.autoInit()` a mano. Preline 4
no inicializa nada por su cuenta: sin ese autoInit, pestañas, modales y menús
son HTML muerto.

## Patrones del panel

**Pestañas con badges.** La navegación del comercio es un array
`id => [label, icon, badge, tono]` que `vendors/tabs/nav.blade.php` dibuja.
El badge es numérico (productos del menú, órdenes recientes, gente del equipo)
y tiene tres tonos: neutro, `ok` y `alerta` — este último para insumos bajo
mínimo, que es el único número que debe doler a primera vista.

**Los parciales se comparten, no se duplican.** `resources/views/vendors/tabs/*`
reciben `$urls` (cada puerta pone las suyas) y `$puede` (capacidades del
usuario). El organizador entra por `/event-panel/comercios/{id}` y el encargado
por `/event-vendor` con su comercio implícito: renderizan la misma pantalla.

**La pestaña sobrevive al guardado.** Todo formulario termina en `back()`, o sea
una recarga, y el hash no viaja al servidor. La pestaña activa se recuerda en
`sessionStorage` por ruta y el hash queda para compartir el enlace. Sin esto,
quien editaba el menú volvía siempre al Resumen.

**Modal de ítem.** Cada producto del menú abre su propio overlay: cabecera con
avatar y chips (Alimentos/Bebidas, Simple/Con receta), precio, exención de
ITBIS, insumo vinculado y escandallo si es receta. Si la validación falla, el
modal se reabre con lo tecleado — el formulario manda `_modal` y la vista lo
compara contra `old('_modal')`.

**El copy fiscal cambia con la modalidad.** Los formularios no dicen «Precio» a
secas: dicen «Precio sin ITBIS» o «Precio con ITBIS incluido» según el modo
vigente del comercio. No es cosmética: decirlo mal invita a cargar precios con
el impuesto dentro cuando el POS lo va a sumar.

**Detalle de venta (patrón order-details).** Marco gris con tarjetas interiores:
cabecera de metadatos (estado con punto de color, número de orden en mono,
fecha local, total), tarjeta triple «Dónde se vendió / Pago / Resumen», línea de
tiempo con barras (Creada → Cobrada; Anulada en rojo; «Abierta en el POS» con
punto latiendo) y «Lo vendido» línea a línea con su ITBIS congelado — «Exenta»
en violeta cuando toca. El Resumen suma reembolsos, neto y la comisión del
organizador con el porcentaje que estaba pactado al momento de la venta.
Imprime con `window.print()`. La misma vista sirve a las dos puertas cambiando
solo el layout.

## Estructura del panel del organizador

- **Sidebar corto**: Negocios y Eventos. Nada más. Todo lo del comercio vive en
  pestañas dentro de su perfil (Resumen, Menú, Ventas, Transacciones,
  Inventario, Usuarios, Configuraciones).
- **No hay selector de contexto.** El contexto lo fijan la puerta y el usuario:
  el organizador ve su cuenta entera; el comercio del encargado es implícito por
  su usuario, jamás elegido por URL.
- **Dashboard**: cuatro KPIs (ventas de hoy, últimos 30 días, cajas abiertas,
  comercios), serie de área de 14 días con ApexCharts, «Ventas por comercio» a
  30 días y «Tu comisión por evento» acumulada. Todo tras `reports.view_tenant`:
  sin el permiso la pantalla muestra un vacío que explica a quién pedirlo, no un
  403. El gráfico se salta solo si ApexCharts no está cargado, así que con el
  layout de fallback la pantalla sigue en pie.
- **Fechas en `America/Santo_Domingo`**: los cortes de «hoy» y las fechas
  mostradas convierten explícitamente. En reportes eso no es cosmético.

Pendiente: la vista de evento en vivo (leaderboard de puestos, semáforo de
stock, terminales sin sincronizar, liquidación).

## La casa del comercio

`/event-vendor` no tiene sidebar a propósito: ese mundo es UNA sola casa.
Cabecera con la inicial del comercio, el nombre de la cuenta, enlace al POS
—solo si el usuario puede operarlo— y salir. Debajo, las mismas pestañas
compartidas, recortadas por capacidades: sin `reports.view_unit` no aparece la
pestaña Ventas ni sus números.

## Entrada única

`/entrar`: una pantalla, correo o nombre de usuario, «mantener la sesión
abierta» y un enlace al POS al pie para el que se equivocó de puerta.
`HomeForUser` es la única pieza que decide a dónde va cada quien —
`/saas-admin`, `/event-panel`, `/event-vendor` o `/pos` — para que login,
rebotes y enlaces no puedan contradecirse. La raíz `/` no es un panel: es un
desvío a esa misma decisión.

## POS — decisiones de experiencia

1. **Dos apps, un motor.** `/pos` y `/event-pos` renderizan la misma vista con
   distinto `data-modalidad`, título y manifiesto: cada una se instala con su
   nombre y su icono, y el cajero solo ve la suya. Duplicar el código offline
   sería el error caro; si algún día divergen, el punto de corte es el
   componente de pantalla, no el motor.
2. **Oscuro y plano.** Fondo `#090e1a`, radio de 4px en todo, acento cian con
   degradado solo en los botones primarios. **El POS no usa Tailwind**: tiene su
   propio juego de tokens CSS en `App.vue` y CSS `scoped` por componente.
   No hay tema claro.
3. **Cuatro pantallas**: Login, Caja (abrir/cerrar), Venta y Ventas del turno.
   La venta es catálogo con buscador y filtro de categorías a la izquierda,
   ticket fijo de 340px a la derecha; colapsan en pantallas chicas.
4. **Táctil sin ceremonia.** Tarjeta de producto de 108px de alto (96 en
   pantallas chicas), botón de cobrar a todo el ancho del ticket con el total
   dentro. Lo que se toca en caliente es grande; lo administrativo, normal.
5. **Cobro**: total en tipografía grande, método en un segmented control
   (efectivo / tarjeta / transferencia), chips de billetes (200 / 500 / 1.000 /
   2.000 / 5.000) y «Exacto», y una línea de «Vuelto» o «Faltan». Un cobro por
   orden — no hay pago dividido. Tarjeta y transferencia van siempre por el
   monto exacto. Hay guard contra el doble toque: un doble tap crearía una venta
   duplicada real que la idempotencia del servidor no puede unir.
6. **Desglose fiscal siempre visible**, y adaptado: el ITBIS es por producto
   (exento o gravado) y por modalidad de la cuenta — en `included` la etiqueta
   dice «ITBIS incluido» y se extrae del precio, en `added` dice «ITBIS 18 %» y
   se suma al cobrar. La propina legal del 10 % es un switch y se calcula sobre
   la base sin impuesto. El cálculo del POS espeja el del servidor, redondeo por
   línea incluido; al sincronizar manda el servidor.
7. **Sin PIN.** El cajero entra con usuario y contraseña; el dispositivo se
   identifica con un id propio guardado en Dexie y el servidor devuelve un token
   Sanctum. Las acciones sensibles se gobiernan por permisos traídos en el
   bootstrap, no por PIN de supervisor: el reembolso exige `sales.refund` y el
   botón sencillamente no existe para quien no lo tiene.
8. **Estado de sincronización** en la barra: píldora En línea / Sin señal (con
   punto verde latiendo) y contadores «N por sincronizar» / «N en revisión».
   Son botones: abren la **bandeja del dispositivo**, una hoja con una fila por
   venta —estado, importe, mensaje de error del servidor— y dos acciones,
   Reintentar y Descartar. Nunca aparece sola; nunca bloquea la venta.
9. **Ventas del turno**: lo cobrado en la caja actual, buscable por número
   legible (P0041), con chip de estado, método, total y «−X devuelto» en los
   reembolsos parciales. El modal de devolución ofrece el pendiente ya cargado y
   exige motivo, con cuatro motivos frecuentes como atajo.
10. **Cierre de caja** en la barra, lejos del botón de cobrar: se declara lo
    contado y la pantalla de resultado muestra Esperado / Contado / Diferencia
    (verde o roja), con el aviso de que nada se retoca desde el POS.
11. **Sin teclado numérico propio**: `inputmode="decimal"` más chips de atajo
    (billetes al cobrar, fondos al abrir caja). Se revisará si el teclado del OS
    estorba en los equipos reales.
12. **App shell offline**: el service worker precachea el cascarón y los assets
    con hash leyendo `/build/manifest.json`, y solo cachea respuestas OK. Los
    DATOS nunca pasan por ahí: viven en Dexie y en la API.

## Super admin — lo que hay

`/saas-admin` (Filament, herramienta interna) tiene cuatro recursos: Tenants con
sus usuarios, **Plantillas de rol** editables en BD, Tipos de negocio y Tipos de
comida. Las plantillas de rol son la pieza importante: el superadmin crea roles
nuevos y la puerta de entrada de cada usuario se deriva sola de sus permisos.

No están construidos (y el doc anterior los daba por hechos): impersonation,
salud de sincronización global, secuencias NCF por tenant, métricas SaaS.

## Sistema visual

- **Puertas web**: Tailwind v4 + tokens de Preline. Acento sky/`sky-600`,
  semánticos teal (ok), ámbar (aviso), rojo (peligro), violeta (exención de
  ITBIS). Números con `tabular-nums`.
- **POS**: sistema propio de variables CSS (`--bg`, `--panel`, `--accent`,
  `--ok/--warn/--bad`) y clase `.money` con cifras tabulares.
- **Tipografías**: el tema Pro carga Inter (más Domine y Kode Mono); el layout
  de fallback usa Instrument Sans; el POS usa el system stack. No hay una fuente
  única y por ahora no hace falta.
- **Idioma**: es-DO; moneda `RD$ 1,234.00`; fechas absolutas en zona del negocio.
- **Número de orden**: `publicNumber()` (P0001, serie por comercio y canal) es lo
  que ve la gente, siempre en mono. La `client_ref` del outbox aparece solo como
  referencia técnica en gris pequeño.

## Marca blanca — estado real

Hoy solo hay **logo por comercio** (`vendors.logo_path`, se sube en la pestaña
Configuraciones) y se ve únicamente en el panel. No hay color de acento en la
base de datos, ni marca en el POS, ni tickets impresos, ni correos
transaccionales. Lo que el doc anterior describía sigue siendo el destino, no el
presente.

## Pendientes conocidos

- El layout del tema Pro está **sin adaptar**: su sidebar todavía trae los
  enlaces de la plantilla (`href="#"`), el avatar de demo y un logo que apunta a
  `/panel`, ruta que ya no existe. Solo se cablearon título, `@vite` y
  `@yield('content')`.
- En el layout de fallback, el resaltado del ítem activo compara contra
  `panel.vendors` / `panel.events`, nombres previos al renombrado de puertas:
  con las rutas actuales (`event-panel.*`) no se ilumina nunca.
- El POS está escrito sin tildes («Sin senal», «En linea»). Hay que decidirlo:
  documentarlo como decisión o corregirlo, pero no dejarlo como accidente
  callado frente a la regla es-DO de este mismo documento.
- Sin `/business`, sin vista de evento en vivo, sin tema claro del POS y sin
  impresión de comprobantes.

