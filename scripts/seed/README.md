# Siembra y servidor para las capturas

Material **temporal** para regenerar las capturas del manual, de las fichas y de la presentación
(`scripts/capture-*.mjs`). No forma parte de la aplicación y no se autocarga.

- `TmpSeedShotsCommand.php` — comando `tmp:seed-shots`: partes (graves y no, notificados y pendientes),
  sanciones (pendiente, vigente con tareas, antigua con seguimiento), notas, tramos con guardias, ausencias con
  actividad y adjunto, eventos (la excursión con alumnado asociado y una actividad en borrador), categorías de recursos compartidos con sus reservas (y tramos de martes a viernes)
  y entradas del registro de actividad, todo fechado alrededor de «hoy» (lunes
  2026-10-05). **Cópialo a `src/Command/` para usarlo y bórralo después**: allí rompe PHPStan (regla que prohíbe
  `getRepository()`/`find()` fuera de los repositorios) y no debe commitearse.
- `TmpSeedExitsCommand.php` — comando `tmp:seed-exits`: salidas del aula (propias, ajenas con más de 10, 20
  y 30 minutos fuera, y ya terminadas) y un pase prioritario, fechadas respecto a `SHOTS_NOW` (por defecto, el
  mismo lunes 2026-10-05 09:30 UTC). Se ejecuta **después** de `tmp:seed-shots` y se copia a `src/Command/` igual
  que aquel. Lo usan `capture-exits-manual-shots.mjs` (manual) y `capture-exits-shots.mjs` (fichas móviles).
- `router-clock.php` + `index-clock.php` — router del servidor PHP integrado con el reloj de la aplicación fijado
  (`Clock::set(MockClock)`, variable `SHOTS_NOW`), para que «hoy» sea un día lectivo aunque se capture en fin de
  semana. Usar con `php -S ... -t public scripts/seed/router-clock.php`.

El procedimiento completo (BD desechable, plantillas de instantánea, CSS, correo anulado…) está en
[`skills/screenshots.md`](../../skills/screenshots.md).
