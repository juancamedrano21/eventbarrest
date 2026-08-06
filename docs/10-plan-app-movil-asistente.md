# 10 · Plan de desarrollo — la app móvil del asistente (white-label por evento)

> **Fecha:** 2026-08-04 · **Fuente:** presentación vendida a Bocao Food Fest 2026
> ([`BoletuTeam/bocao-presentation`](https://github.com/BoletuTeam/bocao-presentation),
> analizada entera: los 6 pilares de `landing-bocao/`, las dos minutas, la estrategia
> white-label y los PDF de Boletu Enterprise y B-Access), más el inventario del
> backend EventBarRest tal como está hoy.
>
> **Qué es:** la app que lleva el público del festival en su teléfono, iOS y
> Android, con la marca del EVENTO al frente (no la nuestra). Cada evento arma SU
> app: qué módulos lleva, en qué orden, con sus colores, sus personajes y sus
> textos. Es la pieza que conecta todo el argumento de venta: la boleta, el menú,
> el pedido que entra solo a la cocina, el push de «tu pedido está listo», el
> saldo, el pasaporte y el Wrapped viven en el mismo teléfono.
>
> **Qué NO es:** ni B-Host (la app móvil del ORGANIZADOR: otra app, otro plan),
> ni nada del staff (el staff entra por B-Access con pulsera/cintillo, no por
> aquí).

---

## 1. Las dos decisiones ya tomadas

**Boleta = integración con Boletu.** La boleta digital YA vive en producción en
la app de Boletu (el PDF Enterprise la enseña: «Cada asistente lleva su entrada
viva en la app de Boletu», con QR dinámico cada 30 s, transferencia por código
de 6 dígitos y validación offline). No se reconstruye: la app nueva consume esa
capacidad por API. **Gate externo:** negociar el contrato de API con el equipo
de Boletu (emisión del QR dinámico —¿SDK con secreto local o endpoint?—,
transferencia, estados de la boleta, cuotas con boleta retenida).

**Una base, módulos por evento — no N forks.** La experiencia es «cada evento
arma su app»: módulos que se encienden, se apagan y se ordenan por evento, con
personalización profunda. Eso descarta tanto apps clonadas a mano como una
única app genérica.

## 2. Arquitectura de la app

**Flutter, con esta separación de responsabilidades:**

| Capa | Dónde vive | Qué decide |
|---|---|---|
| Identidad de tienda | *Flavor* por evento (build) | Icono, nombre, bundle id / package |
| Manifiesto del evento | Backend (runtime) | Qué módulos van, en qué orden, colores, fuentes, personajes, textos, arte del mapa |
| Módulos | Paquetes Flutter independientes | boleta · menús · pedidos · saldo · itinerario · mapa · pasaporte · misiones · feed · cam · wrapped |

El binario es un cascarón que al arrancar pide el manifiesto de SU evento y se
construye a sí mismo — el mismo patrón del KDS: cascarón tonto, cerebro en el
servidor. Cambiar la app de un evento (encender un módulo, cambiar una promo,
un color) NO recompila ni pasa por revisión de tienda.

**Por qué Flutter y no lo demás:** el white-label a escala es su terreno (flavors
nativos, un solo código, N identidades de tienda); rendimiento real para el feed
y la cámara; camera/push/NFC maduros. React Native quedó segundo (JS es más
cercano al equipo, pero el puente nativo complica cámara/NFC/offline y las
variantes a escala son menos limpias). Nativo doble descartado: duplica cada
feature con un equipo pequeño. Capacitor ya se descartó una vez en este
ecosistema (ADR-005 y el README del KDS) y las razones aplican aquí con más
fuerza: esta app ES nativa en sus exigencias (cámara, push, NFC, offline).

### La regla de tiendas, y cómo se resuelve — DECIDIDO

**Modelo elegido: una app por evento, y la primera (Bocao) sale bajo la cuenta
de Boletu.** De la segunda en adelante, cada organizador publica bajo la suya.

El contexto, porque la regla se malinterpreta fácil. Apple 4.2.6 rechaza las
apps «hechas de una plantilla comercializada» salvo que **las suba el dueño del
contenido**. De quién sea el código NO cuenta: la regla mira cuántas apps casi
idénticas aparecen en la tienda. «Solo le cambiamos el skin» es exactamente el
perfil que persigue.

La misma guía nombra la alternativa —un binario agregador «como una app de
eventos con entradas separadas para cada evento»— y se **descartó a conciencia**:
es lo que más rápido saldría, pero mata el argumento central del pitch, que es
que el festival vive dentro de SU marca y no dentro de la del proveedor.

Por qué el orden elegido es de bajo riesgo:

- La 4.2.6 castiga el **patrón**, no la app. Un reviewer que ve una sola app de
  festival publicada por Boletu no tiene con qué compararla; el riesgo aparece
  con la tercera y la cuarta desde la misma cuenta.
- De la segunda en adelante, publicar bajo la cuenta del cliente es
  **literalmente lo que la regla prescribe**. Boletu compila y sube por ellos
  añadiéndose como developer a su equipo, que está permitido.
- Y existe red de seguridad: **Apple permite transferir apps entre cuentas**
  conservando reseñas, valoraciones y usuarios instalados. Publicar bajo Boletu
  no es irreversible, es un préstamo de cuenta. (Verificar las condiciones de
  transferencia ANTES de necesitarla, no el día que haga falta.)

**Consecuencia para el calendario:** el DUNS sale del camino crítico de Bocao
2026. Deja de ser tarea del primer mes y pasa a ser plan B en el cajón — el
cliente lo arranca sin prisa, para cuando quiera su propia ficha o por si la
publicación bajo Boletu se rechaza.

Lo que NO cambia: cada app sigue necesitando su *flavor* (bundle id, icono,
nombre) porque son fichas distintas en la tienda, y el CI debe firmar y subir
por evento. La arquitectura de manifiesto ya lo contempla y serviría igual para
el modelo agregador si algún día se quisiera para clientes pequeños.

## 3. La puerta nueva en el backend: `event-app` (ADR-010)

El asistente es un **actor nuevo** que hoy no existe: `User` es solo staff, y el
comprador es un `customer_name` en la orden. La puerta sigue el patrón ADR-007
(una puerta por audiencia) con lo aprendido de las dos existentes:

- **Cuenta de asistente** (tabla propia, NO `users`): registro por teléfono/email
  con OTP, perfil mínimo. Es la identidad que ata TODO: boleta ↔ cuenta ↔
  pulsera ↔ tarjeta digital ↔ pasaporte ↔ fotos. Ese *binding* es el modelo de
  datos central de la app — la presentación lo asume en cada pilar (los premios
  de boletería acreditan saldo, la recarga en efectivo carga «la pulsera o la
  tarjeta de la app», el Wrapped cruza todo) y nunca lo describe.
- **Tokens Sanctum de larga vida** esquivando las dos trampas ya documentadas en
  `AuthenticateKdsDevice`: `guard => ['web']` (una sesión web abierta
  autenticaría a esa persona) y `sanctum:prune-expired` que borra por
  `created_at`.
- **Backstop de VendorScope**: falla ABIERTO sin vendor en contexto. Todo
  endpoint público que fije contexto por URL replica el `abort_unless` del KDS.
- **Rate limiting y CORS propios**, con la lección del alta del KDS: la IP hoy
  la escribe quien llama (`trustProxies('*')`); acotar los proxies del borde es
  requisito de esta puerta, no opcional.
- **Revalidar todo en cada petición** (doctrina EnsurePosCapability), ETag/304
  para lo que se sondea, y **push real para el estado del pedido** — miles de
  teléfonos preguntando «¿está listo?» por polling multiplicaría la carga que el
  KDS ya pone; el tablero de cocina NO se reutiliza para esto.

## 4. Fases

### Fase 0 · Cimientos (sin pantallas todavía)

El barrido del backend dejó un **gate duro**: hoy el backend vive detrás de un
túnel ngrok con `APP_DEBUG=true`. No se expone una API pública así.

1. **Despliegue productivo** del backend (Railway): `APP_DEBUG=false`, dominio
   real, `trustProxies` acotado al borde (esto además cierra el techo de ~3 h
   del PIN del KDS documentado en `bootstrap/app.php`), CORS, Redis/colas.
2. **ADR-010**: la puerta `event-app`, la cuenta de asistente y el OTP.
3. **Manifiesto white-label** por evento: branding + módulos + textos, editable
   desde `event-panel`, servido con ETag.
4. **Infra de push**: FCM + APNs, tokens de dispositivo por cuenta, colas de
   envío masivo, y los **eventos de dominio** que hoy no existen (ninguna
   transición dispara nada: `AdvanceKitchenTicket` → LISTA tiene el dato
   `ready_at` pero no el mecanismo).
5. **Cascarón Flutter**: navegación modular, theming desde manifiesto, CI que
   compila por flavor y firma por evento. Apertura de cuentas de tienda (Apple
   DUNS ya en marcha).

### Fase 1 · El evento en el bolsillo (lectura, sin dinero)

El MVP presentable: la app abre, es Bocao de arriba a abajo, y sirve.

- **Menús** — adaptar el catálogo existente (productos con foto, precio,
  categoría ya están; el endpoint actual exige token de staff): API pública por
  comercio + promos con ventana horaria + «plato estrella» + filtros. El panel
  del comercio ya edita el catálogo: «cada restaurante actualiza su carta en
  vivo» sale casi gratis.
- **Itinerario** — modelo nuevo de programa del evento (hoy `Event` solo tiene
  nombre, venue y fechas): actividades/tarimas/horarios, agenda personal, y
  recordatorios push **server-side** (el line-up cambia en vivo; recordatorios
  locales quedarían desactualizados — ambigüedad detectada en el barrido,
  resuelta hacia servidor).
- **Mapa** — v1: el arte ilustrado del festival con zonas tocables (puestos,
  tarima, baños, recarga) SIN «estás aquí». El pin en vivo exige georreferenciar
  arte que no es cartografía; es una v2 honesta, no un recorte.
- **Mi boleta** — integración Boletu según el contrato del gate. Si el contrato
  se atrasa, la app arranca sin boleta PERO el hueco es visible en el pitch: la
  primera card del Pilar 2 es esta.
- **Push del organizador** — consola en `event-panel`: esquema programado antes
  + disparo en vivo durante (el tile del bento lo promete tal cual).
- **Espera por puesto en vivo** — ya existe la materia prima (KDS + tiempos):
  derivar minutos de espera por cocina y publicarlos ordenados de menor a mayor.
  Es la promesa de la torre de control («tu público decide con el dato en la
  mano») y nos diferencia sin hardware.

### Fase 2 · El dinero (Bocao Pay + pedido anticipado)

Todo lo de esta fase depende de dos gates: **pasarela de pago online** (hoy no
hay ninguna — `PaymentMethod` es efectivo/tarjeta/transferencia presencial; en
RD la decisión es AZUL vs CardNet vs Stripe, y condiciona tokenización y
cuotas) y la **decisión de dominio del ADR: el pedido móvil no pasa por ninguna
gaveta** — meterlo en la sesión del cajero contaminaría el arqueo (fondo +
efectivo − devoluciones). Sesiones virtuales por canal o serie contable aparte.

- **Monedero (Bocao Pay)** — ledger de saldo por cuenta de asistente (centavos
  enteros, misma disciplina), recargas con tarjeta in-app y en efectivo en
  stands (el staff acredita), movimientos, y la tarjeta visual con skins de
  personajes. **Decisión legal pendiente:** el saldo remanente tras el evento
  («tuya de recuerdo» implica que persiste) — reembolso vs expiración tiene
  implicaciones regulatorias en RD.
- **Pedido anticipado** — carrito por puesto, cobro contra saldo, orden con
  canal `mobile` (el enum ya está reservado y la serie pública ya resuelve la
  numeración: M0042 sobre la misma secuencia del comercio), la comanda entra
  sola al KDS con badge de canal «App» (el KDS de la presentación ya lo pinta),
  estado nuevo **«recogido»** después de LISTA (hoy LISTA es terminal y la
  tarjeta cae del tablero), código de retiro, y el push «Tu pedido está listo —
  retíralo en La Parrilla, muestra el código 104» colgado del evento de dominio
  de la Fase 0.
- **ETA antes de ordenar** («listo en ~12 min») — sale de `KitchenTimings`
  (mediana por puesto), que ya existe.
- **Pagar en el puesto desde la app** — la presentación vende «paga con un toque
  desde el teléfono» y eso, en iOS, **no se puede cumplir con NFC** (Apple solo
  abre HCE a terceros en el EEE, no en RD). La forma honesta y universal: **QR
  dinámico de pago en la app que el POS escanea** (el POS es nuestro; añadirle
  el escaneo es barato). NFC-HCE puede añadirse solo-Android después si se
  quiere el gesto del toque. Esto hay que comunicárselo al cliente ANTES de que
  lo descubra: el copy del Escenario B promete un gesto que iPhone no permite.
- **Offline** — el «Bocao Pay cobra sin internet» de la presentación es del
  LADO DEL POS (saldo prepago, sincroniza al reconectar): encaja con el diseño
  offline-first del POS existente. La app en sí no promete operar sin señal,
  pero el predio saturado es el caso normal: todo lo de esta fase se diseña con
  reintentos idempotentes (`client_ref` ya es el patrón de la casa).

### Fase 3 · Engagement (lo que convierte la app en argumento de patrocinio)

- **Ratings + feed social** por restaurante (fotos de platos, estrellas), con
  moderación por IA antes de publicar — definiendo latencia máxima, apelación y
  quién revisa lo rechazado (la promesa de «feed en vivo» y «moderado antes de
  publicar» conviven solo si la moderación tarda segundos).
- **Pasaporte del degustador** — sellos por puesto probado. **Decisión de
  diseño previa:** qué acredita un sello (la transacción cashless/pedido en el
  puesto es lo verificable; «probar» no es observable). El premio al mejor
  degustador necesita criterio definido. Los datos crudos ya existen (órdenes
  inmutables por vendor y cuenta).
- **Misiones de patrocinadores** — motor de misiones con regla y premio. La
  presentación vende que «la app publica, valida y premia sola»: para misiones
  con foto, presupuestar validación humana asistida por IA y regla clara de
  desempate en «el primero gana» (concurrencia real).
- **Bocao Cam** — cámara desechable in-app: rollo limitado, revelado diferido
  al cierre de cada noche (trigger operado desde el panel), marcos brandeados
  (uno patrocinable), storage S3 + moderación. Alimenta el Wrapped.
- **Wrapped** — al cierre del festival: resumen compartible 9:16 por asistente
  (personajes, puestos, plato mejor calificado, fotos), generado de su
  actividad real, compartir nativo.
- **Motor de premios de boletería** (si el contrato Boletu lo trae): los
  regalos por compra aterrizan como saldo, personaje exclusivo o entrada
  anticipada — los tres materializan en sistemas nuestros.

## 5. Riesgos que la presentación no cuenta (y el plan sí)

1. **Conectividad del público.** La red privada del Pilar 6 conecta puestos,
   puertas y terminales — NO los teléfonos del público. Pedido anticipado,
   push, esperas en vivo y feed dependen de datos móviles en un predio con
   +6.000 personas saturando las celdas. Mitigación: payloads mínimos, ETag en
   todo, reintentos idempotentes, y plantear al organizador wifi de cortesía
   por zonas como parte de la infraestructura que ya vende.
2. **iOS y el NFC** (§Fase 2): el gesto vendido no existe en iPhone fuera del
   EEE. QR de pago es la respuesta; decirlo temprano.
3. **La identidad única** es el modelo de datos más delicado: boleta (Boletu) ↔
   cuenta (nuestra) ↔ pulsera (cashless) ↔ tarjeta digital. Cada flecha es un
   flujo de vinculación que hay que diseñar, y el Wrapped/pasaporte solo
   funcionan si las flechas existen.
4. **Escala del push masivo**: «todos los teléfonos a la vez» (promo flash) son
   miles de envíos en segundos; colas y proveedores dimensionados desde Fase 0.
5. **Dos poblaciones**: el Escenario A se vende «sin teléfono». Todo debe
   funcionar para asistentes CON app y SIN app sin romper liquidación ni
   pasaporte (la app suma, nunca es requisito para comprar).
6. **Saldo remanente** (legal RD) y **revocación offline** (Kill Switch vs QR
   offline: ventana de revocación — es terreno de Boletu, pero el contrato de
   API debe nombrarla).

## 6. Dependencias externas (gates), en orden de urgencia

| Gate | Bloquea | Acción |
|---|---|---|
| Contrato de API con Boletu (boleta, QR, transferencia) | Mi boleta (Fase 1) | Reunión técnica ya |
| Cuentas de tienda del organizador (Apple DUNS, Play) | Publicar (fin de Fase 1) | Iniciar primer mes |
| Pasarela de pago online RD (AZUL/CardNet/Stripe) | Toda la Fase 2 | Evaluar y contratar en Fase 1 |
| Despliegue productivo del backend | Toda API pública | Fase 0, semana 1 |
| Confirmaciones del cliente (rollo de la Cam, reglas de premios, datos reales) | Detalles Fase 3 | Lista al cliente |

## 7. Lo que ninguna presentación dice, pero las tiendas exigen

La revisión de App Store rechaza por esto, así que es alcance de la app aunque
ningún pilar lo venda:

- **Borrado de cuenta dentro de la app** (Apple 5.1.1(v)): hay registro de
  asistente, así que es obligatorio. La otra cara del OTP de la Fase 0.
- **Reportar contenido y bloquear usuarios** (Apple 1.2): obligatorio para
  apps con contenido de usuarios — y el feed y la Bocao Cam lo son. Entra con
  la Fase 3, junto a la moderación por IA que ya estaba en el plan.
- **Alcohol**: el pedido anticipado vende cerveza («2x1 en cervezas»). Eso
  implica clasificación 17+/adultos en las dos tiendas y una decisión de
  barrera de edad en el flujo de compra. Nadie lo menciona en ningún documento
  de la presentación.
- **Privacidad**: política publicada, Ley 172-13 de RD (datos personales),
  etiquetas de App Privacy, y permisos (push, cámara, ubicación) pedidos en su
  momento y con su porqué — no en ráfaga al abrir.
- **Los básicos de operar**: crash reporting y analytics desde la Fase 0 (la
  atribución UTM del Pilar 1 sugiere conectar la analítica con lo que Boletu ya
  mide), canal de soporte/ayuda visible, y términos de uso.

Aparte, dos huecos de FUENTE que el análisis no puede cerrar solo: el PDF de
Boletu Enterprise trae 4 de sus 21 slides (los que faltan podrían contener el
flujo de compra y Apple/Google Wallet, que la estrategia da por existente sin
documento que lo respalde — a la agenda de la reunión con Boletu), y todos los
números del sitio son datos de demo marcados «a confirmar» con el cliente.

## 8. Qué ya está construido que la app aprovecha directo

Catálogo con fotos y precios por comercio · KDS con tres estados y `ready_at`
(el push de «listo» es colgar un evento de una transición que ya existe) ·
tiempos de cocina (mediana/p90 → ETA y esperas publicadas) · canal `mobile`
reservado en el enum de ventas con numeración pública resuelta · idempotencia
por `client_ref` · inmutabilidad de la venta pagada · ITBIS y propina legal
(toda cifra de la app sale de los desgloses, nunca de `sum(total_cents)`) ·
multi-tenancy de dos ejes con sus guardas · patrón de puerta por audiencia con
dos implementaciones de referencia y sus trampas documentadas.

La lección de arquitectura del KDS aplica entera: **cascarón tonto, cerebro en
el servidor, y cada garantía con su porqué escrito al lado.**
