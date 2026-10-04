# Siembra y servidor para las capturas

Material **temporal** para regenerar las capturas del manual, de las fichas y de la presentación
(`scripts/capture-*.mjs`). No forma parte de la aplicación y no se autocarga.

- `TmpSeedShotsCommand.php` — comando `tmp:seed-shots`: partes (graves y no, notificados y pendientes),
  sanciones (pendiente, vigente con tareas, antigua con seguimiento), notas, tramos con guardias, ausencias con
  actividad y adjunto, eventos y entradas del registro de actividad, todo fechado alrededor de «hoy» (lunes
  2026-10-05). **Cópialo a `src/Command/` para usarlo y bórralo después**: allí rompe PHPStan (regla que prohíbe
  `getRepository()`/`find()` fuera de los repositorios) y no debe commitearse.
- `router-clock.php` + `index-clock.php` — router del servidor PHP integrado con el reloj de la aplicación fijado
  (`Clock::set(MockClock)`, variable `SHOTS_NOW`), para que «hoy» sea un día lectivo aunque se capture en fin de
  semana. Usar con `php -S ... -t public scripts/seed/router-clock.php`.

El procedimiento completo (BD desechable, plantillas de instantánea, CSS, correo anulado…) está en
[`skills/screenshots.md`](../../skills/screenshots.md).
