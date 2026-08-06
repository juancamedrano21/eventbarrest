# 12 · Tarjeta guardada con Cybersource — diseño de integración

> **Fecha:** 2026-08-06 · **Fuente:** documentación oficial de Cybersource /
> Visa Acceptance (developer.cybersource.com), investigada con tres lentes:
> almacenamiento (TMS), captura móvil y cobro con token. Las URLs concretas
> están al final de cada sección del análisis original; aquí va lo decidido.
>
> **Objetivo de producto:** el asistente guarda su tarjeta UNA vez en la app,
> y después compra comida con dos toques; el pedido pagado entra solo al KDS.
> Boletu ya procesa con Cybersource en producción (boletería web), así que
> merchant ID, credenciales y conocimiento del Business Center ya existen.

---

## ⚠️ Lo urgente primero, y no es de la app

**Flex/Microform v0.11 y v1 mueren el 31 de agosto de 2026** (EOL definitivo
31 de enero de 2027). Si la boletería web de Boletu que hoy procesa en
producción usa Microform v0.11 (transient tokens con prefijo `mf-0.11.0`),
**el plazo de migración vence este mes**. Hay que auditarla YA, con o sin app.
Todo lo nuevo nace en v2 (`/microform/v2/sessions`, `/flex/v2/tokens`).

---

## 1. La decisión de captura: webview con Microform v2, página nuestra

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

## 7. La lista para el account manager / adquirente

Todo esto NO está en la doc y solo lo responde Cybersource o el adquirente:

1. ¿El MID productivo de Boletu tiene **TMS habilitado** (customer +
   paymentInstrument) y `TOKEN_CREATE`? ¿La app usa ese MID o uno propio?
2. ¿**DOP** está habilitado como moneda de proceso en ese MID (y en sandbox)?
3. ¿El adquirente acepta **CIT con credencial guardada sin CVV**, o exige
   recaptura del CVV por compra? (Cambia el flujo de dos toques.)
4. ¿**3DS es exigido** en RD para compras in-app con tarjeta guardada?
   (Existe un portal local — cybersource.portaldom.do — que conviene revisar.)
5. Habilitación de **network tokens** sobre el MID + cobertura de emisores
   dominicanos.
6. Confirmar por soporte que el patrón **webview + Microform v2** mantiene la
   elegibilidad SAQ A (la frase explícita de webviews está en la doc de v0.11;
   la de v2 no la repite).
7. Formato de **evidencia del consentimiento** de guardado que espera el
   adquirente.
8. Credenciales de **sandbox** (apitest) — las de producción no sirven ahí.

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
