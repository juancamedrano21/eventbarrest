# ADR-011 — La cuenta del asistente: el primer actor de plataforma

**Fecha**: 2026-08-06 · **Estado**: aceptada e implementada (segundo slice de la app)

## Contexto

El segundo slice de la app móvil del asistente añade lo que el primero dejó
fuera a propósito: una cuenta. Seis endpoints bajo `/api/event-app/cuenta`
—pedir código, entrar, perfil, actualizar, salir, borrar— definidos en el
contrato compartido con el repo de la app.

La cuenta de hoy es mínima (nombre y email), pero es la pieza sobre la que se
apoya todo el doc 11: la identidad que mañana ata **boleta ↔ pulsera NFC ↔
monedero ↔ pasaporte**, y que Apple exige poder borrar desde dentro de la app
(5.1.1(v)). Lo que se decide aquí no es un CRUD: es **quién es el asistente
dentro de la plataforma**, y esa decisión condiciona todos los slices que
vienen.

Tres hechos del código pesaron en cada decisión:

1. **`TenantScope` falla CERRADO** y todo lo demás que camina por la
   plataforma pertenece a una cuenta de negocio: el staff (`users`), las
   tabletas del KDS, las ventas.
2. **Sanctum tiene dos trampas medidas y documentadas** en
   `AuthenticateKdsDevice`: `guard => ['web']` autentica una sesión web
   abierta sin pedir credencial ninguna, y `sanctum:prune-expired` borra por
   `created_at`, matando tokens de larga vida en silencio.
3. **La IP la escribe quien llama** (`trustProxies(at: '*')`, deuda conocida):
   cualquier freno por origen es esquivable por quien ataca y un cubo
   compartido para el público honesto de un festival.

## Decisión

### Un actor de PLATAFORMA: la cuenta no lleva `tenant_id`

`event_app_accounts` (con sus satélites `event_app_sessions` y
`event_app_login_codes`) vive **fuera de la convención multi-tenant**: sin
`tenant_id`, sin `BelongsToTenant`, con el email como índice único **global**.
Es el primer actor de plataforma que no es el superadmin.

El motivo es la razón de ser de la cuenta. El asistente de Bocao es **el mismo
asistente** en el próximo festival, de otro organizador: si la cuenta colgara
de un tenant, habría una identidad por organizador y ninguna flecha del doc 11
(boleta ↔ cuenta ↔ pulsera ↔ monedero) podría cruzar de un evento al
siguiente. El pasaporte entre ediciones y el saldo que sobrevive al evento
—los dos argumentos de venta— serían imposibles por esquema.

Las tres tablas están registradas por nombre en `SchemaConventionTest` con
este argumento, igual que se hizo con las tablas globales que ya existían. Y
la consecuencia operativa está escrita en la puerta: **el middleware del
asistente no llama al `ContextResolver`** — no hay tenant que resolver. El día
que un endpoint con sesión hable además de UN evento (pedidos, saldo), el
contexto lo fijará el evento de la URL, como en la puerta pública, y no esta
identidad.

### Token propio con el patrón de la casa, no Sanctum

`event_app_sessions`: el token en claro existe una vez —al entrar— y en la
base solo vive su sha256; la búsqueda es una igualdad indexada por petición.
`AuthenticateEventAppAccount` revalida TODO en cada petición (el token existe,
no está revocado, la cuenta sigue existiendo) y persiste `last_used_at` con el
mismo freno de escritura que la batería del KDS: como mucho una vez por
minuto.

Sanctum se descartó por sus dos trampas conocidas, que aquí serían peores que
en el KDS: el teléfono de un asistente es exactamente el sitio donde puede
haber una sesión web abierta de otra cosa (`guard => ['web']` la autenticaría
sin código), y un token «de larga vida» que muere a los quince días por
`prune-expired` significaría miles de sesiones caducando en silencio en el
bolsillo del público.

La revalidación completa por petición no es celo: **es la única revocación que
existe**. Borrar la cuenta tiene que apagar todos sus teléfonos en la
petición siguiente, y eso solo pasa si la puerta pregunta siempre.

### Entrar sin contraseña: el OTP de 6 dígitos

No hay contraseña: entrar es demostrar el control del buzón. El código se
genera con `random_int` (CSPRNG), vive 10 minutos, se guarda en sha256 y se
compara con `hash_equals`. Solo hay **uno vigente por email** —el índice único
de `event_app_login_codes` lo garantiza: pedir otro pisa la fila— y es de un
solo uso: canjeado, se borra.

Para un público de festival, la contraseña es el peor de los dos mundos: se
olvida entre ediciones (la cuenta se usa dos veces al año) y es un secreto
robable en un volcado. El OTP no deja nada que robar —el hash de un código ya
muerto no abre nada— y nada que olvidar.

**El 422 `codigo_invalido` es UNO para incorrecto, caducado y quemado.**
Distinguirlos contaría a quien prueba códigos si alguien pidió uno y cuándo.
Por lo mismo, el 202 de pedir código es idéntico exista o no la cuenta — y no
igualando dos ramas, sino **no teniendo ramas**: el camino de emisión no
consulta `event_app_accounts` en ningún punto, así que no hay oráculo posible
ni por cuerpo, ni por estado, ni por reloj. Y `nombre` es opcional SIEMPRE,
nunca «obligatorio si la cuenta es nueva»: exigirlo según exista la cuenta
convertiría la validación —que corre antes de comprobar el código— en un
oráculo de enumeración gratuito. De ahí que `name` sea nullable.

### Los fallos queman el CÓDIGO, jamás la cuenta

Cinco intentos fallados matan el código (`failed_attempts`, mirado ANTES de
comparar: acertar no revive un código quemado). La cuenta ni se consulta en
ese camino: **no existe «cuenta bloqueada»**, que sería un botón de apagado
que cualquiera puede pulsar contra un buzón ajeno. Pedir otro código es
gratis y el nuevo nace entero. Es la regla de frenos de la casa aplicada
literalmente: lo que muere es lo que se repone gratis.

Cinco y no tres: quien teclea de un pantallazo se equivoca dos o tres veces
sin mala fe, y contra un espacio de un millón de combinaciones en diez
minutos, cinco intentos no compran nada a quien adivina.

Y esa aritmética la sostiene la BASE, no PHP: el contador sube con un
incremento atómico (`failed_attempts = failed_attempts + 1`), porque con el
valor absoluto —leer, sumar, guardar— dos fallos en vuelo sumaban uno y cada
tanda paralela de adivinanzas costaba UN intento: el tope real era cinco por
el número de trabajadores de php-fpm, no cinco. Por lo mismo, **gastar el
código bueno lo decide la base**: el DELETE condicionado devuelve cuántas
filas borró y solo quien borró una emite sesión — dos canjes simultáneos del
mismo código no pueden abrir dos sesiones.

### El freno de emisión: por DESTINO, no por IP

Seis códigos por buzón y por hora; el séptimo es 429 `codigo_pedido_demasiado`.
La llave es el **sha256 del email normalizado** (minúscula, sin espacios — la
misma normalización que usa la cuenta y la búsqueda del código, o cada
variante de mayúsculas estrenaría cubo). Hasheado y no en crudo porque el
RateLimiter de Laravel pasa toda llave por `htmlentities`: «josé@» y «jose@»
—dos buzones legales distintos— colapsaban al mismo cubo, y josé recibía el
429 con la bandeja vacía. El hash además deja de guardar el correo en claro
en la tabla de caché.

Por IP se descartó con el argumento ya medido en esta misma puerta (ADR-010):
la IP la escribe quien llama, así que el cubo no cuenta contra el atacante y
sí contra el público que sale por el NAT de dos operadores. Seis se pensó
desde el asistente impaciente: pide, el correo tarda, toca «reenviar» dos o
tres veces, vuelve a pedir — no llega a seis en una hora. Y el freno **no
puede negar a la persona legítima**: solo raciona emitir códigos nuevos;
entrar con el vigente no pasa por él. Lo peor que consigue quien inunda un
buzón ajeno es que su dueño espere para un código *nuevo* — con el último que
le llegó entra igual.

Encima del freno fino hay un **cortacircuitos global de volumen**: la puerta
entera, sumando todos los buzones, emite como mucho 600 códigos por minuto
(429 `emision_saturada` con `Retry-After`). Existe porque el freno por
destino no acota el volumen — un llamante que rota buzones inventados siembra
filas y correos sin techo, y el correo saliente sin límite quema la
reputación del dominio remitente, que es una denegación de días para todos.
El número sale del peor pico legítimo (6.000 asistentes registrándose en la
hora de puertas ≈ 100/min) por seis de margen; la llave es CONSTANTE para que
quien ataca no pueda dirigir el contador contra nadie; y la ventana de un
minuto hace corto el apagón. Es la única excepción medida a la regla de
frenos —un ataque que lo alcance degrada el alta un rato para todos— y se
paga a sabiendas porque la alternativa es peor, más larga y también para
todos. Como todo freno de esta puerta, raciona EMITIR: entrar con un código
vigente no pasa por él jamás.

### El transporte del correo es un contrato, no una llamada

`TransporteDeCodigos` (interfaz) con una implementación de log para
desarrollo. El proveedor real es de otro slice; cuando llegue, cambia el
binding de `AppServiceProvider` y nada más — el código que decide (emitir,
canjear, frenar) no conoce el transporte. Los tests sustituyen el binding por
un espía, que es exactamente el mismo gesto.

La puerta de entorno es del binding, no de un comentario: **en producción,
mientras no haya proveedor real, el transporte enlazado falla ruidoso**
(`TransporteDeCodigosSinProveedor`, con un mensaje que dice qué configurar)
en vez de escribir el OTP en claro en `storage/logs` — un fichero que ven el
despliegue, los respaldos y cualquier agregador. Un 500 operable en la
primera petición se ve y se arregla en minutos; los códigos filtrados al log
no se ven nunca.

### Borrar borra de verdad, y ese endpoint es el dueño de anonimizar

`DELETE /cuenta` elimina la fila y todas sus sesiones (explícito en el
código, con la foreign key `cascadeOnDelete` como backstop de la base). Hoy
nada cuelga de la cuenta, así que borrar de verdad es correcto y es lo que
Apple exige. El día que existan pedidos o saldo, **lo que cambia es ese
método**: decidirá qué se anonimiza (el rastro fiscal de una venta no se
borra) y qué se destruye. La decisión queda para ese slice, pero su dueño ya
tiene nombre y docblock.

## Lo que se descartó

- **Sanctum para el token del asistente** — las dos trampas medidas
  (`guard => ['web']`, `prune-expired`); el patrón de la casa ya existía en
  el KDS y se copia entero, freno de `last_used_at` incluido.
- **Contraseña, sola o como alternativa al código** — un secreto que el
  público olvida entre ediciones y que sí es robable en un volcado; además
  duplicaría los caminos de entrada y sus superficies de error.
- **`tenant_id` en la cuenta (la cuenta como hija del organizador)** — parte
  la identidad en una por organizador y hace imposibles el pasaporte entre
  ediciones y el monedero que sobrevive al evento.
- **Freno por IP en pedir código** — esquivable por quien ataca y cubo
  compartido para el público (el mismo razonamiento medido de ADR-010).
- **Bloquear la CUENTA a los cinco fallos** — un botón de apagado contra un
  buzón ajeno; viola la regla de frenos de la casa. Lo que muere es el
  código.
- **Distinguir en la respuesta el código caducado del incorrecto o del
  quemado** — contaría a quien prueba si alguien pidió código y cuándo; la
  app tampoco puede hacer nada distinto con esa información.
- **`nombre` obligatorio en el registro** — la validación corre antes de
  comprobar el código, así que «falta el nombre» diría «este email no
  estaba»: un oráculo de enumeración gratis. Nullable, y la app enseña el
  email mientras no haya nombre.

## Consecuencias

- Existe un actor de plataforma con puerta propia: middleware sin
  `ContextResolver`, tablas sin `tenant_id` registradas por nombre en
  `SchemaConventionTest`, y ningún código de evento en sus rutas.
- Los slices que vienen (pedidos, saldo, pasaporte) cuelgan de
  `event_app_accounts.id` y fijan su contexto de tenant por el evento de la
  URL, nunca por la identidad del asistente.
- El token del asistente no abre ninguna otra puerta (POS, KDS, panel) ni las
  llaves de esas puertas abren esta: cada audiencia sigue entrando por la
  suya (ADR-007), y hay tests que lo sostienen en las dos direcciones.
- Cambiar de transporte de correo es cambiar un binding; el 202 del teléfono
  no se entera.
