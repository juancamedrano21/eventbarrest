# EventBarRest — instrucciones para Claude

SaaS multi-tenant en Laravel 13 / PHP 8.4 para dos mundos: **bares y restaurantes**
(operación permanente) y **festivales de comida** (operación temporal, con comercios
terceros que pagan comisión al organizador). Incluye POS offline, KDS de cocina en
tablet y paneles por audiencia.

---

## Antes de tocar nada: lee la memoria del proyecto

**[`docs/CHANGELOG.md`](docs/CHANGELOG.md)** es la memoria completa: 73 hitos con QUÉ se
construyó, **POR QUÉ** se decidió así (lo que se rompía, lo que se descartó) y las
**GARANTÍAS** que dejó cada uno. Tiene además un **glosario** de los términos propios
del proyecto y un **índice de garantías transversales**.

Léelo así, según lo que vayas a hacer:

- **Siempre:** el «Índice de garantías transversales». Son las reglas que se pagan lejos
  de donde escribes. Si una te estorba, no la esquives: busca su hito y entiende qué se
  rompía sin ella.
- **Si no entiendes un término** (comanda, puerta por audiencia, penitencia, índice
  ciego, propina legal): el glosario, al final.
- **Antes de tocar un área concreta:** los hitos de esa área (el mapa por áreas está
  arriba del changelog). El porqué de cada decisión ya está escrito; no lo re-derives.

Después, según el trabajo:

| Documento | Cuándo |
|---|---|
| [`docs/README.md`](docs/README.md) | Índice, estado real de cada doc, qué está y qué no está construido |
| [`docs/06-fiscal-rd.md`](docs/06-fiscal-rd.md) | **Antes de tocar dinero.** ITBIS, NCF, propina legal |
| [`docs/adr/`](docs/adr/) | ADR-001 a 009. Antes de cambiar una decisión de arquitectura |
| [`docs/11-app-movil-especificacion.md`](docs/11-app-movil-especificacion.md) | Trabajo de la app móvil del asistente |
| [`docs/10-plan-app-movil-asistente.md`](docs/10-plan-app-movil-asistente.md) | Fases, gates y riesgos de la app móvil |

Cuando un documento y el código se contradigan, **manda el código** — y avisa.

## Los tres repos, y cómo se rompen entre sí

| Ruta | Qué es |
|---|---|
| `.` (raíz) | Documentación, ADR y el CHANGELOG. Repo propio |
| `eventbarrest/` | El backend Laravel y todos los frontends web. Repo propio |
| `../payrone-table-kds/` | La app Android del KDS (Kotlin + WebView). Repo propio |

Cada uno se commitea por separado. `eventbarrest/` está en el `.gitignore` de la raíz.

**No son independientes.** El APK es un cascarón de ~700 líneas: **la pantalla que
enseña la vive en `eventbarrest`**. Un cambio aquí puede dejar la tablet inútil sin que
nada falle en el navegador ni en los tests, y sin que nadie se entere hasta el festival.

Antes de tocar cualquiera de estos, abre `../payrone-table-kds/`:

| Si tocas… | Puede romper |
|---|---|
| `resources/views/kds.blade.php` — **el contenedor `<div id="kds">`** | El vigía del APK y la **única salida** de su pantalla de error dependen de que ese id exista y tenga hijos. Renombrarlo, envolverlo o montar el KDS en otro elemento deja la tablet con el cartel de error puesto **para siempre**, con el servidor perfectamente encendido |
| `resources/js/kds/**` | Corre **dentro del WebView de la tablet**, no solo en el navegador. `npm run build` en `eventbarrest` cambia lo que ejecuta el APK sin tocar su repo. Y el WebView es más viejo que Chrome de escritorio: por eso se evitó `AbortSignal.timeout` y se usa `AbortController` a mano |
| Añadir un recurso externo al KDS (fuente, CDN, script de métricas) | El APK **intercepta y corta todo lo que no venga de su servidor** (`shouldInterceptRequest`). Funciona en el navegador y sale roto en la tablet |
| `app/Http/Controllers/Kds/**`, `AuthenticateKdsDevice` | El contrato del alta y del tablero: `device_identity`, las cabeceras `X-Kds-Bateria` / `X-Kds-Cargando`, el ETag. El puente nativo del APK las manda |
| La clave de firma del APK (en el otro repo) | Cambia el `ANDROID_ID`, que está acotado por firma → **duplica toda la flota** en `kds_devices`. La salida limpia es revocar la flota **antes** de rodar el APK nuevo |

**Al terminar un cambio en cualquiera de los tres, el CHANGELOG que se actualiza es
siempre el de la raíz** (`docs/CHANGELOG.md`): es la memoria común. El APK usa el área
`APK (tablet)`.

---

## Al terminar un cambio: actualiza el CHANGELOG

**Esto no es opcional.** Todo cambio con sustancia —una feature, un arreglo de fondo,
una decisión— entra en `docs/CHANGELOG.md` antes de dar el trabajo por terminado.

Añade el hito bajo su fecha, en la sección «Historia», con este formato:

```markdown
#### [Área] El hito en una línea, en lenguaje humano
<sub>`hash1` · `hash2`</sub>

**Qué.** Qué se construyó o cambió: modelos, pantallas, comandos, endpoints.

**Por qué.** La decisión. Qué se rompía, qué se eligió y **qué se descartó**.
Esto es lo único que no se puede reconstruir leyendo el código: es lo que más
cuidado merece.

**Garantías.** Los invariantes que este hito deja establecidos y que nadie puede
romper después. Omite la línea si no establece ninguno.
```

Las áreas son: `Plataforma`, `Negocio`, `Eventos`, `POS`, `KDS`, `APK (tablet)`,
`Seguridad`, `Documentación`.

Y además:

- **Si el cambio establece una regla transversal**, súbela también al «Índice de
  garantías transversales».
- **Si introduce un término nuevo** del dominio, al glosario.
- **Si cambia lo que está construido**, actualiza `docs/README.md` — un índice que
  miente sobre lo que existe es peor que no tenerlo.
- **Si es una decisión de arquitectura**, escribe su ADR en `docs/adr/`.

Agrupa: una feature construida en cuatro commits es **un** hito con cuatro hashes, no
cuatro entradas.

---

## Convenciones que no se negocian

**Estilo de código y comentarios**

- Comentarios y mensajes de commit en **español**, explicando el **porqué**, nunca el
  qué. Nombres de test en inglés (`it('does something')`). Sin emoji, sin exclamaciones.
- Escribe código que se lea como el que lo rodea: misma densidad de comentarios, mismos
  nombres, mismo idioma.

**Los tres candados (verdes antes de dar nada por hecho)**

```bash
cd eventbarrest && ./vendor/bin/pint --dirty && ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G && php artisan test
```

Pint (preset laravel + `declare_strict_types`), PHPStan nivel 6, Pest 4. Si tocas
`resources/js`, además `npm run build`.

**Dinero, tiempo y fiscalidad**

- Todo en **centavos enteros**. Pesos con decimales solo en pantalla.
- **La propina legal (10%) viaja DENTRO de `total_cents`.** Toda cifra agregada sale de
  `SalesSummary` o de los desgloses — **nunca** de `sum('total_cents')`.
- Cortes de día en `config('app.business_timezone')` (RD), no UTC.
- Costo desconocido es `null`, jamás `0`.

**Aislamiento**

- `TenantScope` falla **CERRADO** (sin contexto no se ve nada).
- `VendorScope` falla **ABIERTO** (solo filtra con comercio en contexto). Todo endpoint
  que fije contexto por URL necesita su backstop explícito, y las tablas nuevas del
  mundo evento llevan `vendor_id` NOT NULL como red de la base.
- `insert()` y `upsert()` están prohibidos en modelos scopeados.

**La historia no se reescribe**

- Una venta pagada o anulada es inmutable. Precios, ITBIS, comisión y área de despacho
  se **congelan en la línea** al vender.
- Tenants y productos no se borran: se suspenden o desactivan.

**Frenos**

Un freno **nunca** puede dejar fuera a quien hace las cosas bien. Un contador que sube
quien ataca, sobre algo que él elige, es un botón de apagado con otro nombre. Si lo que
diseñas puede negar un acierto, está mal.

---

## Trabajo de campo (POS, KDS, APK)

**Cascarón tonto, cerebro en el servidor.** El APK y el POS no deciden reglas de
negocio: se cambian desplegando, no reinstalando veinte aparatos.

**Una pantalla nunca puede mentir sobre estar viva.** Si no hay datos frescos, lo dice.
Una pantalla congelada que parece viva es el peor fallo posible en una cocina.

Trampa de Blade que ya mordió cuatro veces: **no escribas `@php(` ni `@endphp` literales
dentro de un comentario `{{-- --}}`** — rompe la compilación.

---

## Seguridad y secretos

- **Nunca** commitear: `.env`, el tema Preline **Pro** (`resources/panel-theme/` y
  `public/panel-theme/`, ambos en `.gitignore` — son plantillas compradas), keystores
  (`*.jks`, `*.keystore`).
- **Nunca** pegar en el chat el valor de `SUPERADMIN_PASSWORD` ni de `DOCS_PASSWORD`.
- **Deuda abierta conocida:** `trustProxies(at: '*')` hace que la IP la escriba quien
  llama, así que todo límite por IP es esquivable. Cerrarlo exige acotar `at:` a los
  rangos del borde (ngrok / Railway) — decisión de despliegue pendiente del dueño.
- El backend corre hoy tras un túnel ngrok con `APP_DEBUG=true`. Es el gate de cualquier
  API pública.

## Git

Commitea cuando el trabajo esté verde, en el repo que toque. **No empujes salvo que se
te pida.** Los mensajes de este proyecto son largos a propósito: cuentan qué se rompía y
qué se descartó, porque son la fuente del CHANGELOG.
