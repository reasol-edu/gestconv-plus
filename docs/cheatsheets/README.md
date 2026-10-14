# Fichas de referencia rápida

Fuente de las fichas de referencia rápida («cheatsheets») de GestConv+: una por cada función básica
del profesorado, pensadas para consultarse en el móvil en mitad de una clase, más una para el
equipo directivo sobre cómo configurar un curso académico nuevo. Todos los comandos de esta página
se ejecutan desde la raíz del repositorio con `make`, no directamente desde esta carpeta.

## Ficheros

- `registrar-parte.md`, `notificar-parte.md`, `registrar-ausencia.md`, `tareas-sancion.md`,
  `mis-guardias.md`, `editar-contacto.md`, `registrar-sancion.md`, `instalar-app.md`,
  `busqueda-rapida.md`, `registrar-nota.md`, `autorizar-salida.md`, `quien-esta-fuera.md` — una ficha [Marp](https://marp.app) por función del
  profesorado, con capturas móviles.
- `tareas-alumnado.md` — ficha para el alumnado y las familias sobre cómo consultar las tareas de una
  sanción con el enlace o el código; sin capturas (clase `.sin-capturas`), pensada para entregarse
  junto con el enlace.
- `curso-nuevo.md` — ficha para el equipo directivo, con capturas de escritorio (a diferencia de
  las demás, pensadas para el móvil), usando la clase `.captura-escritorio` de `theme.css`.
- Las 14 fichas comparten el mismo mecanismo de versión/fecha que `docs/slides/gestconv-plus.md`
  (marcadores `{{VERSION}}`/`{{PUB_DATE}}` sustituidos por `make cheatsheets`).
- `theme.css` — tema Marp compartido por las 14 fichas (página A4 vertical, paleta de marca).
- `img/` — capturas de pantalla referenciadas desde las fichas (móviles, salvo las de
  `curso-nuevo.md`).
- `ficha-*.pdf` y `_build.md` — salidas generadas por `make cheatsheets` (ver abajo); no se editan
  a mano.

## Generar los PDF

```bash
make cheatsheets
```

Genera un PDF independiente por ficha (`docs/cheatsheets/ficha-<nombre>.pdf`) con
[marp-cli](https://github.com/marp-team/marp-cli) (vía `npx`), usando el tema compartido
`theme.css`. Requiere Node.js/`npx`; el mensaje de error del propio comando indica cómo
instalarlo si falta. La versión y la fecha del pie se toman automáticamente de
`app.version`/`app.pub_date` en `config/services.yaml`, igual que en la presentación.

`make docs` genera también las fichas junto al manual y la presentación.

## Regenerar las capturas

```bash
node scripts/capture-cheatsheet-shots.mjs
```

Usa Playwright con el viewport de un iPhone 13 contra un servidor local ya arrancado con datos de
demostración (ver `SHOTS_BASE_URL`/`SHOTS_OUT_DIR` en el propio script). Nunca se ejecuta contra la
base de datos real: sigue el mismo flujo de base de datos desechable que el resto de scripts
`scripts/capture-*.mjs`.

Las capturas de `autorizar-salida.md` y `quien-esta-fuera.md` salen de
`node scripts/capture-exits-shots.mjs`, que además **prueba** la sección Salidas en un navegador real
(una treintena de comprobaciones: flujo, cronómetro, niveles de color, ancho 390 px, sondeo en vivo…). Necesita datos
de salidas sembrados con un comando de consola desechable (ver la cabecera del script) y una base de datos
desechable: crea salidas y concede un pase como efecto secundario.

Las capturas de escritorio de `curso-nuevo.md` se regeneran aparte, con
`node scripts/capture-curso-nuevo-shots.mjs`: a diferencia del resto, este script ejecuta él mismo
el flujo completo de puesta a punto de un curso académico (lo crea, lo activa, importa profesorado
y alumnado...), así que necesita su **propia** base de datos desechable, recién sembrada — no
reutilizar la misma pasada que el resto de capturas, porque deja un curso académico nuevo activado
como efecto secundario. Ver el comentario de cabecera del script para el detalle.
