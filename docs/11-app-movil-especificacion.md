# 11 · La app móvil del asistente — especificación funcional

> **Qué es.** La app que el público del festival lleva en su teléfono, iOS y
> Android, con la marca del EVENTO al frente. Cada evento arma SU app: qué
> módulos lleva, en qué orden, con sus colores, sus personajes y sus textos.
>
> **Qué NO es.** Ni B-Host (la app del ORGANIZADOR, otro producto), ni nada del
> staff (el staff entra por B-Access con pulsera o cintillo), ni el POS, ni el
> KDS. Esos ya existen y están en el [CHANGELOG](CHANGELOG.md).
>
> **Relación con el resto de docs.** El [doc 10](10-plan-app-movil-asistente.md)
> es el plan de acción (fases, gates, riesgos, decisión de stack). Este doc 11 es
> la especificación funcional: **qué tiene que hacer la app**, módulo por módulo.
> El [CHANGELOG](CHANGELOG.md) es lo que ya existe por debajo.

---

## 1. Los 14 módulos

Cada módulo se enciende, se apaga y se ordena **por evento**. Un festival de
comida los quiere casi todos; un concierto de una noche quizá solo boleta, mapa
e itinerario.

### 1 · Mi boleta
La entrada vive en la app, sin imprimir nada.

- QR **dinámico** que se regenera cada 30 s: una captura de pantalla queda
  inservible al instante.
- **Válida sin conexión** — el QR se genera en el propio teléfono.
- **Transferible** a otra persona con un código único de 6 dígitos; el QR del
  emisor se revoca y todo queda auditado.
- Se valida en puerta en segundos.
- Compra en cuotas: la boleta aparece **retenida** y se libera sola al completar
  los pagos, con avisos de próxima cuota y de cobro fallido.

> **Fuente: Boletu.** Esto ya existe y corre en producción allí. La app lo
> consume por API — no se reconstruye. Ver §5.

### 2 · Menús
La carta de cada restaurante del festival.

- Fotos, precios y ratings por producto; **plato estrella** destacado.
- Filtros por antojo, restricción alimentaria o presupuesto.
- **Cada comercio la actualiza en vivo** desde su propio panel.
- **Promos con ventana horaria** («2x1 en cervezas hasta las 6:00 pm»).

### 3 · Pedidos anticipados
Ordenar sin hacer fila. Es el módulo que conecta la app con la cocina.

- Carrito por puesto; se paga con saldo de la app o con la pulsera.
- La comanda **entra sola al KDS** marcada como canal «App», sin ocupar la caja.
- **Tiempo estimado antes de ordenar** («listo en ~12 min»), derivado de la cola
  real del puesto.
- Push **«Tu pedido está listo — retíralo en La Parrilla, muestra el código 104»**.
- Código de retiro y estado del pedido en pantalla.

### 4 · Bocao Pay (tarjeta digital)
El monedero del asistente dentro de la app.

- Saldo recargable: con tarjeta desde la app, o en efectivo en los stands de
  recarga (el staff acredita).
- Últimos movimientos.
- **Skins de personajes** para vestir la tarjeta.
- Se la queda de recuerdo al terminar el festival.
- Pago en el puesto desde el teléfono — **por QR que escanea el POS**, no por
  NFC (ver §6, riesgo 2).

### 5 · Itinerario
El programa del evento y la agenda personal de cada quien.

- Line-up y horarios por tarima.
- El asistente **añade shows a su propio itinerario**.
- **Recordatorio push 15 minutos antes** de cada uno, disparado desde el
  servidor (el line-up cambia en vivo).

### 6 · Mapa
El plano del festival.

- El arte ilustrado del evento con **zonas tocables**: puestos, tarima, bar,
  baños, punto de recarga, entrada.
- v2: pin «estás aquí» en vivo y distancia a pie a cada zona (exige
  georreferenciar el arte, que no es cartografía).

### 7 · Esperas en vivo
El dato que reparte las filas solo.

- Minutos de espera **de cada puesto**, publicados y ordenados de menor a mayor.
- Sale del propio KDS y de los tiempos ya medidos — **sin cámaras ni hardware
  extra**, que es como lo resuelven los estadios grandes.

### 8 · Pasaporte del degustador
La mecánica de juego que sube el gasto por persona.

- Un **sello/personaje coleccionable** por cada puesto probado.
- Anillo de progreso (4/6) y **Premio Bocao al mejor degustador** (cena VIP + merch).
- 100% patrocinable.

> **Decisión pendiente:** qué acredita un sello. «Probar» no es observable; la
> transacción en el puesto sí. Y hace falta definir el criterio de «mejor
> degustador» (¿más puestos? ¿más platos? ¿mejor rating?).

### 9 · Misiones de patrocinadores
Inventario de patrocinio nuevo, que hoy no existe.

- Cada marca arma **su misión con su regla y su premio**: misión relámpago con
  foto, escanear 3 marcas → cupón, etc.
- Contador en vivo y adjudicación al primero.
- La app la publica, la valida y la premia.

> **Realismo:** validar «tómate una foto con tu vaso» automáticamente implica
> visión por computador con falsos positivos. Presupuestar revisión humana
> asistida por IA, y una regla clara de desempate en «el primero gana».

### 10 · Feed social y ratings
El contenido que genera el propio público.

- Los asistentes suben **fotos de sus platos** y los califican con estrellas.
- Los ratings alimentan los menús.
- **Moderación por IA antes de publicar** — con latencia máxima definida,
  apelación, y alguien que revise lo que la IA rechaza.

### 11 · Bocao Cam
La cámara desechable del festival.

- Rollo **limitado** dentro de la app: disparas y no ves la foto.
- **Se revela al cierre de cada noche**, con el marco del festival.
- Varios skins de marco, **uno patrocinable**.
- Alimenta el Wrapped.

### 12 · Wrapped
El resumen que cada asistente publica.

- Al cerrar el festival: **sus personajes**, los puestos que visitó, su plato
  mejor calificado, y sus fotos de la Cam.
- Historias 9:16 listas para Instagram, WhatsApp y TikTok.
- Cada asistente publicando es **alcance orgánico con la marca del cliente**, y
  un espacio más que vender a un patrocinador.

### 13 · Notificaciones push
Dos naturalezas que conviven.

- **Del organizador:** esquema programado antes del evento (apertura, promo
  flash, line-up de la noche) + disparo en vivo durante.
- **Transaccionales:** pedido listo, recordatorio de show, cuota por vencer.

### 14 · Premios de boletería
Lo que se vende en la boleta, aterriza en la app.

- Los regalos de las promos de venta se materializan como **saldo cashless**,
  **personaje exclusivo** del pasaporte, o **entrada 1 hora antes**.

---

## 2. Lo transversal

### White-label de verdad
La marca del evento en cada pantalla: logo, colores, tipografía, personajes,
textos y arte del mapa. Y cada evento **enciende, apaga y ordena sus módulos**
sin recompilar.

### La cuenta del asistente
El modelo de datos más delicado, y el que ninguna presentación describe:

```
boleta (Boletu) ── cuenta (nuestra) ── pulsera NFC (cashless)
                        │
                        └── tarjeta digital (Bocao Pay)
```

Cada flecha es un flujo de vinculación que hay que diseñar. El pasaporte, el
Wrapped y los premios de boletería **solo funcionan si las flechas existen**.
Registro con OTP por teléfono o email.

### Dos poblaciones, siempre
El Escenario A del cashless se vende explícitamente **«sin teléfono»**. Todo
tiene que funcionar para asistentes **con** app y **sin** app, sin romper la
liquidación ni el pasaporte. La app suma; nunca es requisito para comprar.

### Lo que exigen las tiendas
No lo vende ningún pilar, pero la revisión de App Store rechaza sin ello:

- **Borrado de cuenta dentro de la app** (Apple 5.1.1(v)) — hay registro.
- **Reportar contenido y bloquear usuarios** (Apple 1.2) — hay feed y Cam.
- **Barrera de edad / clasificación 17+**: el pedido anticipado vende alcohol.
- **Privacidad**: política publicada, Ley 172-13 de RD, etiquetas de App
  Privacy, y permisos (push, cámara, ubicación) pedidos en su momento y con su
  porqué, no en ráfaga al abrir.

### Operación
Analytics y crash reporting desde el día uno, canal de soporte visible, y
términos de uso.

---

## 3. Arquitectura

| Capa | Dónde vive | Qué decide |
|---|---|---|
| Identidad de tienda | *Flavor* de build | Icono, nombre, bundle id / package |
| **Manifiesto del evento** | **Backend, en runtime** | Qué módulos, en qué orden, colores, fuentes, personajes, textos, arte |
| Módulos | Paquetes Flutter independientes | La funcionalidad de cada uno |

**Flutter**, y el binario es un cascarón que al arrancar pide el manifiesto de SU
evento y se construye a sí mismo. Cambiar la app de un evento —encender un
módulo, cambiar una promo, un color— **no recompila ni pasa por revisión de
tienda**.

Es el mismo patrón que ya probamos en el KDS y está en el
[CHANGELOG](CHANGELOG.md): **cascarón tonto, cerebro en el servidor**.

---

## 4. La puerta nueva en el backend (`event-app`)

El asistente es un **actor que hoy no existe**: `User` es solo staff y el
comprador es un `customer_name` en la orden. La puerta sigue el patrón ADR-007
(una puerta por audiencia) heredando las lecciones de las dos existentes:

- Cuenta de asistente en **tabla propia**, no en `users`.
- Tokens Sanctum de larga vida esquivando las dos trampas ya documentadas
  (`guard => ['web']` y `prune-expired` que borra por `created_at`).
- **Backstop de `VendorScope`**, que falla ABIERTO: todo endpoint que fije
  contexto por URL replica el `abort_unless` del KDS.
- Rate limiting y CORS propios — y acotar `trustProxies` al borde, que es
  requisito de esta puerta, no opcional.
- Revalidar todo en cada petición; ETag/304 en lo que se sondea.
- **Push real para el estado del pedido**: miles de teléfonos preguntando «¿ya
  está?» por polling multiplicaría la carga que el KDS ya pone. El tablero de
  cocina **no** se reutiliza para esto.

Toda cifra que la app muestre sale de los desgloses (`SalesSummary`), nunca de
`sum('total_cents')` — la propina legal viaja dentro del total.

---

## 5. Qué ya existe y qué hay que construir

**Ya existe y se aprovecha directo:** catálogo con fotos y precios por comercio ·
KDS de tres estados con `ready_at` (el push de «listo» es colgar un evento de una
transición que ya está) · tiempos de cocina con mediana y p90 (→ ETA y esperas
publicadas) · canal `mobile` reservado en el enum de ventas con la numeración
pública ya resuelta (M0042) · idempotencia por `client_ref` · inmutabilidad de la
venta pagada · ITBIS y propina legal · multi-tenancy de dos ejes con sus guardas.

**No existe en ninguna forma:** cuenta de asistente · API pública · push
(FCM/APNs) · monedero y ledger de saldo · pasarela de pago online · pedido creado
por el cliente y estado «recogido» · programa/agenda del evento · zonas del mapa ·
ratings · pasaporte · boletería · manifiesto de marca por evento.

---

## 6. Los riesgos que la presentación no cuenta

1. **La conectividad del público.** La red privada del predio conecta puestos,
   puertas y terminales — **no** los teléfonos del público. Pedidos, push,
   esperas y feed dependen de datos móviles con +6.000 personas saturando las
   celdas. Mitigación: payloads mínimos, ETag en todo, reintentos idempotentes, y
   plantear wifi de cortesía por zonas como parte de la infraestructura que el
   organizador ya vende.

2. **iOS y el NFC.** El Escenario B vende «paga con un toque desde el teléfono», y
   eso **no se puede cumplir en iPhone**: Apple solo abre HCE a terceros en el
   EEE, no en República Dominicana. La respuesta honesta y universal es **QR
   dinámico de pago que escanea nuestro POS**. NFC-HCE puede añadirse solo en
   Android después, si se quiere el gesto. **Decírselo al cliente antes de que lo
   descubra.**

3. **La identidad única** (§2) es el diseño más delicado y el que la presentación
   asume en cada pilar sin describir en ninguno.

4. **Escala del push masivo**: una promo flash son miles de envíos en segundos.

5. **Saldo remanente** tras el evento: «tuya de recuerdo» implica que la cuenta
   persiste, pero nadie dijo qué pasa con el dinero que sobra. En RD el prepago
   retenido tiene implicaciones regulatorias.

6. **Revocación offline**: el Kill Switch exige revocar ya, pero el QR se genera
   sin conexión. Esa ventana es terreno de Boletu, pero el contrato de API tiene
   que nombrarla.

---

## 7. Dependencias externas (gates)

| Gate | Bloquea | Cuándo |
|---|---|---|
| Contrato de API con Boletu (boleta, QR, transferencia) | Mi boleta | Reunión técnica ya |
| Despliegue productivo del backend (hoy es ngrok con `APP_DEBUG=true`) | Toda API pública | Semana 1 |
| Cuentas de tienda del organizador (Apple DUNS, Play) | Publicar | Primer mes — el DUNS tarda semanas |
| Pasarela de pago online RD (AZUL / CardNet / Stripe) | Todo el módulo 3 y 4 | Evaluar durante la fase 1 |
| Confirmaciones del cliente (rollo de la Cam, reglas de premios, datos reales) | Detalles de los módulos 8-12 | Lista al cliente |

> **Nota sobre las fuentes.** El PDF de Boletu Enterprise que tenemos trae 4 de
> sus 21 slides; los que faltan podrían contener el flujo de compra y el detalle
> de Apple/Google Wallet, que la estrategia da por existente sin documento que lo
> respalde. Va a la agenda de la reunión con Boletu. Y todos los números de la
> presentación (precios, «247 de 1.000», «18/24 fotos») son datos de demo
> marcados «a confirmar»: son **formas de UI** que la app debe poder renderizar,
> no requisitos duros.
