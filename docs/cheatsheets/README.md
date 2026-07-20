# Fichas de referencia rápida

Fuente de las fichas de referencia rápida («cheatsheets») de GestConv+: una por cada función básica
del profesorado, pensadas para consultarse en el móvil en mitad de una clase. Todos los comandos de
esta página se ejecutan desde la raíz del repositorio con `make`, no directamente desde esta
carpeta.

## Ficheros

- `registrar-parte.md`, `notificar-parte.md`, `registrar-ausencia.md`, `tareas-sancion.md`,
  `mis-guardias.md`, `editar-contacto.md` — una ficha [Marp](https://marp.app) por función, con el
  mismo mecanismo de versión/fecha que `docs/slides/gestconv-plus.md` (marcadores
  `{{VERSION}}`/`{{PUB_DATE}}` sustituidos por `make cheatsheets`).
- `theme.css` — tema Marp compartido por las 6 fichas (página A4 vertical, paleta de marca).
- `img/` — capturas de pantalla móviles referenciadas desde las fichas.
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
