# 12 · Tarjeta guardada con Cybersource — diseño de integración

> **Fecha:** 2026-08-06 · **Fuentes:** (1) documentación oficial de Cybersource
> / Visa Acceptance, investigada con tres lentes — almacenamiento (TMS),
> captura móvil y cobro con token; (2) **el código de producción de
> `BuletuV2`**, leído sin tocar nada, que es lo que manda cuando contradice a
> la doc. Las secciones §0.x son las que salen del código real y **corrigen** a
> las de abajo donde chocan.
>
> **Objetivo de producto:** el asistente guarda su tarjeta UNA vez en la app,
> y después compra comida con dos toques; el pedido pagado entra solo al KDS.
> Boletu ya procesa con Cybersource en producción (boletería web), así que
> merchant ID, credenciales y conocimiento del Business Center ya existen.

---

## 0. Corrección: la alarma del EOL de Microform NO aplica

En la primera versión de este documento avisé de que **Flex/Microform v0.11
muere el 31 de agosto de 2026** y que había que auditar la boletería web de
Boletu con urgencia. Al leer el código real: **Boletu no usa Microform.** Usa
**Unified Checkout v1** (`POST /uc/v1/sessions` + el widget
`UnifiedCheckout.js`), donde no hay nada de Flex. Cero referencias a
`flex-microform.min.js`, `mf-0.11` o `Flex.createToken` en todo el proyecto.

La alarma era mía, salida de la documentación y no del código. Queda anulada.
Lo que sí conviene vigilar: el asset del widget está **pinneado a la versión
1.0.0** en la URL, así que su ciclo de vida hay que seguirlo (se cambia por
`PORTALDOM_ASSETS_URL`).

---

## 0.1 Lo que Boletu ya tiene resuelto en producción (y responde media lista)

El análisis del código de `BuletuV2` responde con hechos casi todas las
preguntas que este documento le iba a hacer al account manager:

| Pregunta del §7 | Respuesta desde el código |
|---|---|
| ¿TMS habilitado en el MID? | **Sí.** `payment_plans.tokenized_card_ref` guarda tokens TMS en producción |
| ¿DOP habilitado? | **Sí.** `services.portaldom.currency` = DOP, país DO, locale es_ES |
| ¿CIT/MIT sin CVV? | **Sí.** Las cuotas 2..N se cobran sin tarjeta, sin CVV y sin 3DS |
| ¿3DS exigido en RD? | **Sí, y no es opcional: PortalDOM lo exigió.** Cadena Cardinal completa en los dos flujos |
| ¿Cómo se autentica? | HTTP Signature con tres credenciales de **PortalDOM** (el integrador local de Cybersource en RD) |
| ¿Sandbox? | Sí, por `PORTALDOM_ENV` + `PORTALDOM_API_HOST` (apitest) |

**Y lo más importante: el patrón de tarjeta guardada que este documento
diseñaba ya corre en producción**, en Boletu Cuotas. Es exactamente el mismo
building block:

- **CIT (primer cobro)**: 3DS completo → `/pts/v2/payments` con
  `actionList: ["TOKEN_CREATE"]`, `actionTokenTypes: ["customer","paymentInstrument"]`
  y `initiator: { type: "customer", credentialStoredOnFile: true }`.
- **Cobros siguientes**: `paymentInformation.customer.id` = el token, sin
  tarjeta, sin CVV, sin 3DS.

O sea: **no estamos abriendo camino, estamos siguiendo uno ya certificado con
el adquirente dominicano.**

## 0.2 Las lecciones ya pagadas en producción — copiarlas, no redescubrirlas

Esto es lo más valioso del análisis. Cada una costó un fallo real:

1. **El SDK tiene DOS objetos de configuración.** `ApiClient` arma la URL con
   `Configuration::getHost()`, cuyo default es **apitest**, no con
   `MerchantConfiguration::getRunEnvironment()`. Si solo se setea el segundo,
   se firma con el host correcto y se conecta a sandbox → **401 solo en
   producción**, invisible en pruebas porque ahí el default coincide.
2. **La firma HTTP usa `request-target` SIN paréntesis**, al revés del
   estándar draft-cavage. Con paréntesis la firma es matemáticamente válida y
   Cybersource la rechaza igual.
3. **El mismo transient token cambia de nombre según el endpoint**:
   `tokenInformation.transientTokenJwt` (el JWT completo) en
   `/pts/v2/payments`, pero `tokenInformation.transientToken` (solo el claim
   `jti`, 64 caracteres) en `/risk/v1/*`. Mandar el JWT entero a risk da
   INVALID_DATA — y la doc de PortalDOM que dice «jti» como nombre de campo
   está equivocada.
4. **El indicador de comercio cambia de nombre entre respuestas**:
   `ecommerceIndicator` en check-enrollment, `indicator` en validate-auth, y el
   body de pago lo espera como `ecommerceIndicator`. Sin coalescer se pierde el
   dato justo en el flujo con challenge.
5. **`body.status` es el único árbitro.** Puede venir `responseCode: "00"` con
   código de aprobación válido y `status: AUTHORIZED_RISK_DECLINED` (lo rechazó
   Decision Manager). Leer el código y no el status es cobrar lo que no se
   cobró.
6. **El ancla del encadenado es `processorInformation.networkTransactionId`**,
   NO el id de la transacción. Y la regla es por marca: Visa encadena con el de
   la última exitosa; Mastercard, Amex y Discover siempre con el del CIT
   original.
7. **Cybersource rechaza campos con string vacío** (`fingerprintSessionId`, el
   bloque de autenticación): se incluyen solo si tienen valor.
8. **Si un cobro aprueba sin token o sin networkTransactionId, abortar con
   log crítico.** «Cobrado pero sin credencial guardada» es el peor estado
   posible y Boletu ya lo trata así.

## 0.3 Lo que NO hay que copiar de Boletu

- **`PortalDomDirect` mete PAN y CVV en claro en el servidor Laravel** y los
  retiene cifrados en sesión durante el 3DS. El propio código admite que «no es
  un servicio de tokenización PCI-DSS compliant». Eso es alcance **SAQ D**. La
  app no puede ir por ahí bajo ningún concepto.
- **`directPay()`** (cobro sin 3DS, sin traslado de responsabilidad de fraude)
  sigue vivo sin llamadores, y un comentario desactualizado lo llama «el punto
  de entrada activo». Cuidado con recablearlo por confiar en ese comentario.
- **El lifecycle repartido entre dos repos** (el cron de cuotas vive en Omnia y
  hay lógica de avance de estado duplicada, reconocido en el propio código).
  Para la app: un solo dueño del ciclo de vida.
- **`gateway_response` persiste el body completo de Cybersource**, incluido el
  token TMS. Está oculto en `$hidden` del modelo, pero cualquiera que lea la
  tabla ve la credencial de cobro. Nosotros guardamos el token en su columna y
  no volcamos la respuesta entera.
- **`mapCardType` cae silenciosamente a Visa** para marcas desconocidas. En
  checkout es tolerable; en tarjeta guardada la marca decide la regla de
  encadenado, así que ahí sería un fallo silencioso.

## 0.4 Lo que cambia en nuestro diseño

**La captura pasa a Unified Checkout en webview**, no Microform. Razón: es lo
que ya está certificado con PortalDOM, con el 3DS orquestado por la cadena
Cardinal que ellos exigen, y reutilizamos el gateway entero de Boletu en vez de
integrar una vía distinta. Sigue siendo webview, sigue sin que el PAN toque
nuestro backend, y sigue en SAQ A.

**El 3DS deja de ser una duda: es obligatorio.** PortalDOM lo exige, y eso
significa que la app **necesita la cadena completa de Payer Authentication**
en el alta de tarjeta (device data collection en iframe, check enrollment, y el
step-up del banco si toca). Es trabajo real y hay que presupuestarlo — la
buena noticia es que los cinco desenlaces posibles ya están tipados en el
código de Boletu.

**Lo que sí queda por confirmar con PortalDOM** (y es poco, pero importa): todo
el diseño de Boletu asume `reason: "9"` (installment) con cronograma fijo. La
app necesita la variante **«unscheduled credential on file»** — cobros con
tarjeta guardada, montos distintos, sin calendario. Las banderas exactas de esa
variante hay que reconfirmarlas: es la única pregunta de verdad abierta.

---

## 1. La decisión de captura (revisada en §0.4: Unified Checkout, no Microform)

**Cómo entra la tarjeta a Cybersource sin tocar nuestro backend:** Laravel
sirve una página de captura (Blade) con Microform v2; la app Flutter la abre
en un webview (`webview_flutter` + un JavaScript channel — el mismo patrón del
cascarón del KDS). Los campos del PAN y el CVV viven en **iframes de
Cybersource**, no en nuestra página: nosotros recibimos solo el
`transientTokenJwt`.

Por qué esta vía y no las otras (evaluadas todas):

| Opción | Veredicto |
|---|---|
| **Webview + Microform v2 (elegida)** | Patrón descrito en la doc para apps nativas, elegibilidad **SAQ A** (el alcance PCI mínimo), reutiliza credenciales y conocimiento que Boletu ya tiene, y en Flutter cuesta poco |
| SDKs nativos Flex (iOS/Android) puenteados | Sin releases desde 2022, el sample de Android borrado, guía oficial con links TBD. Dos platform channels contra librerías moribundas |
| Flex API v2 REST directo desde Dart | Criptografía de pago hecha en casa (JWE RSA-OAEP + AES-GCM) y el PAN en memoria de código propio: casi seguro nos saca de SAQ A |
| Unified Checkout en webview | Menos código aún, pero la pantalla de pago es de Cybersource y se configura, no se diseña. Queda de plan B |

Detalles que ya quedaron fijados por la doc:

- El **capture context** lo genera SIEMPRE Laravel (`POST /microform/v2/sessions`,
  con `targetOrigins` = nuestro origen https). Nunca credenciales dentro del APK.
- El **transient token expira a los 15 minutos**: el camino app → Laravel →
  Cybersource es inmediato, no encolable.
- Laravel **valida la firma** del capture context (JWT) antes de servir la página.

## 2. La estructura en TMS: un Customer por asistente, N tarjetas

```
Customer token  (1 por cuenta de asistente)  ← users… no: event_app_accounts
 └── Payment Instrument token  (1 por tarjeta guardada)
      └── Instrument Identifier  (el PAN tokenizado; vive solo en la bóveda de Cybersource)
           └── Network token  (opcional, recomendado — ver §5)
```

**Qué guardamos nosotros** (y es todo lo que guardamos):

- `event_app_accounts.cybs_customer_id` — el id del Customer token.
- Tabla `event_app_cards`: `payment_instrument_id`, `instrument_identifier_id`,
  `brand` (VISA…), `last4` (de la máscara `****1111`), `exp_month`, `exp_year`,
  `is_default`. Con eso la app pinta «Visa ····1111 · 12/28» sin llamar a
  Cybersource en cada render.

**Lo que jamás guardamos:** PAN, CVV (la doc lo prohíbe expresamente:
la CVN «puede usarse en la autorización inicial pero no almacenarse»), ni
usamos nunca el endpoint de tarjeta desenmascarada.

## 3. Los tres flujos

### Alta de tarjeta CON compra (el caso natural: primer pedido)

1. La app captura la tarjeta (webview) → `transientTokenJwt` + el
   **consentimiento explícito de guardado** (la doc lo exige; checkbox + texto
   + timestamp nuestro).
2. Laravel hace **una sola llamada** que cobra y guarda:
   `POST /pts/v2/payments` con
   - `tokenInformation.transientTokenJwt`
   - `processingInformation.capture = true` (venta directa: la comida se
     despacha al momento, no hay envío que esperar)
   - `processingInformation.actionList = ["TOKEN_CREATE"]`,
     `actionTokenTypes = ["customer","paymentInstrument"]`
   - `authorizationOptions.initiator = { type: "customer", credentialStoredOnFile: true }`
     (la bandera CIT de «primera vez que guardo»)
   - `commerceIndicator = "internet"`
   - `clientReferenceInformation.code` = **nuestro `client_ref`** (el patrón de
     idempotencia de la casa mapea 1:1)
   - `orderInformation.amountDetails = { totalAmount, currency: "DOP" }`
3. De la respuesta AUTHORIZED se persisten `customer.id`, `paymentInstrument.id`
   y el `transaction id` **en la orden** (lo necesita el reembolso).
4. La orden queda pagada por canal `mobile` → entra al KDS por el flujo que ya
   existe. **Nada nuevo del lado cocina.**

### Compra de dos toques (las siguientes)

La app manda pedido + id de la tarjeta elegida. Laravel:
`POST /pts/v2/payments` con `paymentInformation.customer.id` +
`paymentInformation.paymentInstrument.id` y
`authorizationOptions.initiator.storedCredentialUsed = true`.
Cero datos de tarjeta en tránsito, cero recaptura.

### Tarjetas adicionales, borrado y cuenta borrada

- **Otra tarjeta:** nueva captura → asociar al Customer existente
  (`POST /tms/v2/customers/{id}/payment-instruments`).
- **Borrar tarjeta:** si es la default y hay otras, primero se marca otra como
  default (restricción de la doc), luego `DELETE` del payment instrument.
- **`DELETE /cuenta`** (ya existe y borra de verdad): se extiende a la bóveda —
  iterar y borrar cada payment instrument, luego el Customer token, y recién
  entonces las filas locales. La cascada del lado Cybersource **no está
  documentada**: no dependemos de ella.

## 4. Idempotencia: el doble cobro no es teórico en un festival

`client_ref` ↔ `clientReferenceInformation.code`. El patrón seguro ante un
timeout con mala señal:

1. Antes de reintentar, Laravel consulta `/tss/v2/searches` por el
   `client_ref`.
2. Si la transacción existe y está AUTHORIZED → no se reenvía; se concilia.
3. El «bloqueo de duplicados de 15 minutos» de Cybersource aparece en foros de
   soporte, **no como contrato del API**: no es la defensa, es un colchón.

### 4.1 Construido y medido (2026-08-07)

Esto ya no es diseño: es `App\Domains\Payments\Actions\BuscarCobroPorReferencia`,
probado contra `apitest.cybersource.com`. Lo que se aprendió al construirlo:

- **El MID de sandbox SÍ tiene la búsqueda habilitada.** HTTP 201, la
  transacción aparece buscando por `clientReferenceInformation.code`, y una
  referencia inexistente devuelve `totalCount: 0` limpio, no un error. Para el
  **MID de producción hay que confirmarlo con PortalDOM**: sin la búsqueda, y
  sin idempotencia, no queda ninguna defensa contra el doble cobro.
- **La búsqueda tarda ~5 s en indexar.** Medido: a 0,3 s del cobro devolvía 0
  resultados y a 4,6 s ya devolvía la transacción. Consecuencia dura: un
  `totalCount: 0` preguntado justo después del corte —que es cuando uno
  pregunta— **no prueba que no se cobró**. Por eso la autorización para
  reintentar no es «la lista vino vacía», es
  `ConciliacionDeCobro::sePuedeReintentar($segundosDesdeElCobro)`, que exige
  además que haya pasado el indexado.
- **La respuesta de la búsqueda NO trae `status`.** La regla dura «`body.status`
  es el único árbitro» vale para `/pts/v2/payments`; el resumen de
  `_embedded.transactionSummaries[]` tiene otra forma y el árbitro ahí es
  `applicationInformation`: aprobado es `reasonCode: "100"` + `rFlag: "SOK"`;
  un rechazo por tarjeta inválida sale `reasonCode: "231"` + `rFlag:
  "DINVALIDCARD"`. Se exige la combinación exacta del éxito: cualquier otra
  cosa, incluido un `rFlag` que no conozcamos, cuenta como no aprobada.
- **Si la búsqueda falla, se falla ruidoso.** «No encontré nada» y «no pude
  mirar» llevan a decisiones opuestas, y devolver lo segundo como lo primero es
  el doble cobro que la conciliación venía a evitar.

Y el desenlace que enciende todo esto es nuevo: un corte de transporte ya no
sale como un rechazo, sale como `DesenlaceDeCobro::Incierto`. La distinción se
hace por **código HTTP y presencia de cuerpo**, no por el tipo de la excepción:
el SDK envuelve en `ApiException` tanto un 400 con cuerpo JSON como un curl que
ni llegó a conectar (`new ApiException($mensaje, 0, [], null)`).

## 5. Network tokens: pedir la habilitación, vale la pena

Con la tokenización de red (Visa/Mastercard): la tarjeta guardada **sobrevive
al reemplazo del plástico** (reemisiones y vencimientos se actualizan solos —
crítico para «guardar una vez, comprar toda la temporada»), criptograma
dinámico por transacción y mejor tasa de aprobación. Requiere habilitación
aparte en Business Center (TRID) y **webhooks** (`TOKEN_STATUS_UPDATED`:
ACTIVE/SUSPENDED/DELETED/EXPIRED) que actualizan nuestras `event_app_cards`.

## 6. 3-D Secure: decisión pendiente del adquirente, no nuestra

La doc solo obliga 3DS donde hay SCA regulatorio (Europa). En RD la
exigibilidad —y quién asume el fraude sin 3DS— la fija el adquirente
dominicano. Si se exige: el Cardinal Mobile SDK es nativo iOS/Android
(puentearlo a Flutter es costo real), o Unified Checkout en webview lo trae
integrado — otro punto para el plan B. **Preguntar antes de construir.**

## 7. Lo que queda por preguntar (ya casi nada)

El análisis del código de Boletu (§0.1) respondió con hechos TMS, DOP, CIT sin
CVV, 3DS, credenciales y sandbox. Queda:

1. **Las banderas de «unscheduled COF»** para cobros con tarjeta guardada sin
   cronograma: Boletu solo tiene certificado el caso `reason: "9"` (cuotas).
   Es la única pregunta técnica de verdad abierta.
2. **¿La app cobra bajo el mismo MID de PortalDOM que la boletería, o uno
   propio?** Con el mismo, las credenciales existen ya. Con otro, hay que
   pedir TMS provisionado en él.
3. **Network tokens** (que la tarjeta guardada sobreviva al reemplazo del
   plástico): Boletu no los usa hoy. Requiere habilitación + webhooks.
4. **`card_not_enrolled` (ECI 07) se cobra igual, sin traslado de
   responsabilidad**: Boletu lo asume a conciencia. La app tiene que tomar esa
   decisión de negocio explícitamente, no heredarla por copiar código.
5. **Evidencia del consentimiento**: Boletu ya versiona el suyo
   (`consent_at` / `version` / `ip`, versión `2026-05-29.v1`). Reutilizar el
   mismo esquema y confirmar que sirve para nuestro caso.
6. **La idempotencia (`v-c-idempotency-id`) hay que pedirla habilitada.**
   Medido el 2026-08-07: el MID de sandbox NO la honra —dos llamadas con la
   misma llave dieron dos cobros, y con cuerpos distintos tampoco dio el 400
   de conflicto que exige la especificación—, con la cabecera demostradamente
   en el request. Sin ella, la consulta de §4.1 es la ÚNICA defensa contra el
   doble cobro.
7. **¿El MID de producción tiene habilitada la búsqueda de transacciones
   (`/tss/v2/searches`)?** El de sandbox sí (§4.1). Si el de producción no la
   tuviera, y sin idempotencia, no quedaría ninguna defensa.
8. **El valor de la clave 27 de la MDD cuando el cobro tokeniza.** Lo único
   verificado es el caso NO tokenizado: Boletu manda siempre
   `TOKENIZATION NO` porque en boletería nunca tokeniza. Que el simétrico sea
   `TOKENIZATION SI` es **deducción nuestra, no dato confirmado**, y así está
   escrito en el código. La MDD es informativa y no decide la autorización
   —comprobado contra apitest: el cobro aprueba igual—, así que no bloquea.

## 8. Orden de construcción propuesto

1. **Sandbox primero** (sin gates): credenciales apitest, y el flujo completo
   en tests de integración Laravel — alta con TOKEN_CREATE, cobro con
   customer.id, refund, void, búsqueda por client_ref. Tarjeta 4111… de test.
2. **Backend:** tabla `event_app_cards` + `cybs_customer_id` en la cuenta;
   endpoints de la puerta `event-app`: capture context, alta de tarjeta,
   listado, default, borrado; checkout del pedido (canal `mobile`, ADR de la
   sesión de caja virtual que ya está anotado); rama tarjeta de `RefundOrder`.
3. **App:** pantalla «Mis tarjetas» (webview de captura + listado + default +
   borrar), y el checkout de dos toques en el módulo de pedidos.
4. **KDS:** nada — ya está. El pedido pagado entra solo. (El push de «listo»
   es el siguiente slice del módulo pedidos, ya diseñado en el doc 11.)
5. **Producción** solo cuando la lista del §7 esté respondida y el backend
   esté desplegado de verdad (el gate de ngrok/APP_DEBUG sigue en pie).

---

**Registro relacionado:** ADR-011 (cuenta del asistente — el ancla de todo
esto), doc 11 §módulo pedidos, y el CHANGELOG para el porqué de cada garantía
heredada (dinero en centavos, client_ref, inmutabilidad de la venta pagada).
