# Presentación de GestConv+

Presentación de introducción a GestConv+ para profesorado de FP, escrita en [Marp]
(Markdown → diapositivas). Pensada para una audiencia mixta: empieza por el qué y el
flujo de trabajo por roles y termina con un bloque técnico de configuración y despliegue.

## Ficheros

- `gestconv-plus.md` — fuente de las diapositivas (Marp).
- `img/` — capturas del entorno de pruebas incrustadas en la presentación.

## Generar el PDF

Desde la raíz del repositorio:

```bash
make slides
```

Genera `docs/slides/gestconv-plus.pdf`. Requiere **Node.js** (usa `npx @marp-team/marp-cli`,
sin instalación global) y `--allow-local-files` para incrustar las capturas locales.

> El PDF no se versiona en el repositorio: lo genera CI en cada release y se publica como
> `gestconv-plus-presentacion-vX.Y.Z.pdf` en los activos del [GitHub Release]. Este comando es para
> previsualización local.

[GitHub Release]: https://github.com/reasol-edu/gestconv-plus/releases

## Otros formatos

Cambia la extensión de salida al invocar marp-cli directamente:

```bash
npx --yes @marp-team/marp-cli docs/slides/gestconv-plus.md --allow-local-files -o docs/slides/gestconv-plus.pptx   # PowerPoint
npx --yes @marp-team/marp-cli docs/slides/gestconv-plus.md --allow-local-files -o docs/slides/gestconv-plus.html   # HTML
```

## Editar y previsualizar

La extensión [Marp for VS Code] ofrece vista previa en vivo. Para regenerar las capturas,
arranca el entorno de desarrollo con datos de demostración (`make fixtures`) y reejecuta el
script de capturas.

[Marp]: https://marp.app
[Marp for VS Code]: https://marketplace.visualstudio.com/items?itemName=marp-team.marp-vscode
