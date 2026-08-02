# 00 — Estado actual del sistema

> Este documento cuenta **qué está construido y cómo opera hoy**. Los demás
> documentos describen el diseño; este describe la realidad.
> Última revisión: **5 de agosto de 2026** · 359 pruebas automatizadas en verde.

## En una frase

EventBarRest ya vende: un cajero puede abrir su caja, cobrar sin señal,
sincronizar cuando vuelve el wifi y cerrar su arqueo — y el organizador ve
sus ventas y su comisión en tiempo real desde el panel.

## Las puertas

Cada audiencia entra por la suya. Todas comparten el mismo login (`/entrar`),
que reconoce quién eres y te lleva a tu sitio.

| Puerta | Quién entra | Qué hace ahí |
|---|---|---|
| `/entrar` | Todos | Correo o usuario. Cada quien acaba en su puerta |
| `/saas-admin` | Superadmin de la plataforma | Cuentas, roles y permisos, catálogos |
| `/event-panel` | Organizador del evento | Sus eventos, sus comercios, sus ventas y su comisión |
| `/event-vendor` | Encargado de un comercio del evento | Su menú, su inventario, sus ventas |
| `/event-pos` | Cajero de un puesto de evento | Vender y cobrar |
| `/pos` | Cajero de una sucursal | Vender y cobrar |
| `/business` | Bar o restaurante independiente | **Pendiente de construir** |

Los dos POS son dos aplicaciones instalables distintas (nombre, icono y
arranque propios) que comparten el mismo motor offline. El login rechaza al
cajero del mundo equivocado indicándole a cuál ir.

## Qué funciona hoy

**Vender.** El POS es una aplicación web instalable que funciona sin
conexión. El cajero abre su caja con un fondo, arma la orden tocando
productos, aplica la propina legal si toca y cobra en efectivo, tarjeta o
transferencia. La venta se guarda **en el dispositivo** y se sincroniza
sola; si el wifi falla a mitad, nada se pierde y nada se duplica.

**Cobrar bien.** El ITBIS se calcula línea a línea y se congela con la
venta. Cada producto declara si está gravado o exento, y cada negocio
declara si su precio ya lleva el impuesto dentro o se suma al cobrar. La
propina legal (10 %) siempre se calcula sobre la base sin impuesto.

**Devolver.** Quien tenga el permiso puede reembolsar una venta cobrada,
total o parcialmente y con motivo obligatorio. La venta no se edita: el
reembolso es un asiento nuevo que la referencia. El arqueo de caja lo
descuenta y los reportes lo restan de las ventas y de la comisión.

**Cuadrar la caja.** Al cerrar, el sistema calcula lo que debería haber en
la gaveta (fondo + cobros en efectivo − devoluciones en efectivo) y lo
compara con lo contado. No deja cerrar con ventas sin sincronizar.

**Controlar el inventario.** Cada producto puede descontar un insumo (una
cerveza descuenta una botella) o tener receta (un mojito descuenta ron,
limón y azúcar). Las compras entran por el libro mayor y el costo promedio
alimenta el margen. El stock puede quedar negativo a propósito: un POS
nunca bloquea una venta por un conteo desfasado.

**Ver el negocio.** El organizador tiene su panel con ventas del día y del
mes, gráfica diaria, desglose por comercio y **su comisión por evento**.
Cada comercio tiene su perfil con los mismos números, sus productos más
vendidos, cómo le pagan y quién le tocó los precios.

## Reglas que el sistema no deja romper

Estas no son recomendaciones: están en el código y hay pruebas que fallan
si alguien las rompe.

- **Una venta cobrada es historia.** No se edita ni se borra, por ninguna
  vía. Corregirla es otro asiento.
- **Lo que se congela, se congela.** Nombre y precio del producto, el ITBIS
  de cada línea, la modalidad fiscal y la comisión pactada quedan grabados
  en la venta. Cambiarlos mañana no reescribe lo cobrado ayer.
- **Nadie ve lo que no es suyo.** Los datos están aislados por cuenta y, en
  los eventos, además por comercio. Lo ajeno no da "prohibido": simplemente
  no existe.
- **Un producto vendido no se borra.** Se desactiva, o dejaría ventas
  apuntando al vacío.
- **Una sola caja abierta por punto de venta**, garantizado por la base de
  datos, no por una comprobación que se pueda saltar.

## Lo que falta

**Para operar de verdad:**
- `/business`: la casa del bar independiente (sucursales, su menú, su
  inventario). Hoy ese mundo vive en el panel viejo de Filament.
- Apagar `/app` cuando lo anterior esté listo.
- Liquidación de eventos: el estado de cuenta por comercio y el corte.

**Fiscalidad DGII** (ver [doc 06](06-fiscal-rd.md)): secuencias NCF, foliado
offline por bloques, nota de crédito para los reembolsos, exportes 606/607
y e-CF. La base ya está: el ITBIS congelado por línea es justo lo que el
comprobante necesita.

**Operación de bar:** comandas a cocina y barra (la clasificación ya decide
el despacho, falta imprimir), anulación de orden desde el POS y descuentos.

**Producción:** el sistema corre en local. Falta despliegue, dominio,
copias de seguridad y monitoreo.

## Cómo leer esta documentación

- **00** (este) — qué hay construido hoy.
- **01–09** — el diseño: visión, arquitectura, datos, roles, POS, fiscal, UI.
- **adr/** — las decisiones importantes y **por qué** se tomaron. Si algo te
  parece raro, la respuesta suele estar aquí.
