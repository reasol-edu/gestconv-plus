# Resolución de problemas

Esta sección cubre los problemas más habituales al desplegar y usar GestConv+. Si no encuentras
aquí lo que buscas, revisa también [Despliegue](09-despliegue.md) y
[Operación y mantenimiento](10-operacion-y-mantenimiento.md).

## No puedo arrancar la aplicación

### «El puerto ya está en uso» / la página no carga

Por defecto, el binario nativo (`start.sh`/`start.bat`/`start.ps1`) escucha en el puerto **8080**.
Si ese puerto ya lo usa otro proceso en tu equipo, la aplicación no arranca o el navegador no llega
a cargar la página.

- Indica un puerto distinto al arrancar: `./start.sh 9000` (Linux/macOS), `start.bat 9000`
  (Windows CMD) o `.\start.ps1 -Port 9000` (PowerShell). Recuerda usar ese mismo puerto en la URL
  del navegador.
- Comprueba qué proceso ocupa el puerto por defecto (`lsof -i :8080` en macOS/Linux, o
  `netstat -ano | findstr :8080` en Windows) y ciérralo, o elige otro puerto libre.
- Si la aplicación se ejecuta con Docker, comprueba que no haya otro contenedor o servicio local
  publicando el mismo puerto en `compose.yaml`.

### macOS bloquea el binario («desarrollador no identificado»)

La primera vez que se ejecuta `frankenphp` en macOS, Gatekeeper puede bloquearlo por no estar
firmado con un certificado de desarrollador de Apple. El script `start.sh` intenta eliminar la
cuarentena automáticamente; si el aviso persiste:

```bash
xattr -d com.apple.quarantine frankenphp
```

Alternativamente, haz clic derecho sobre el binario (o sobre `start.command`/`demo.command`) →
**Abrir**, y confirma en el diálogo que aparece. Ver también
[macOS: aviso de Gatekeeper](09-despliegue.md#macos-aviso-de-gatekeeper).

### Windows bloquea el binario (SmartScreen o antivirus)

Windows SmartScreen puede mostrar un aviso de «Windows protegió tu PC» al ejecutar `frankenphp.exe`
o los scripts `.bat`/`.ps1`, por tratarse de un binario sin firma de un editor reconocido. Pulsa
**Más información** y después **Ejecutar de todas formas**.

Si un antivirus corporativo bloquea o pone en cuarentena el ejecutable, añade la carpeta de la
aplicación a la lista de exclusiones — es la vía habitual para binarios legítimos no firmados en
entornos gestionados por TI.

## No puedo entrar

### He olvidado la contraseña de administrador

Si el propio administrador tiene configurado un correo electrónico y el envío de email está activo
(ver [Notificaciones por email](06-notificaciones-y-email.md)), usa el enlace **¿Has olvidado tu
contraseña?** de la pantalla de acceso: recibirás un correo con un enlace de restablecimiento
válido durante 1 hora.

Si no hay ningún administrador accesible o el correo no está configurado, crea una cuenta de
administrador nueva desde la línea de comandos del servidor:

```bash
php bin/console app:create-admin nuevo-admin
```

(en el paquete nativo, `frankenphp php-cli bin/console app:create-admin nuevo-admin`). Ver
[app:create-admin](08-comandos-de-consola.md#appcreate-admin).

### El navegador dice «la conexión no es privada» en desarrollo

Es normal en local: `symfony serve` genera un certificado TLS autofirmado para servir la aplicación
por HTTPS durante el desarrollo, y los navegadores no confían en él por defecto. Ejecuta
`symfony server:ca:install` una vez para instalar la autoridad de certificación local, o acepta la
excepción de seguridad del navegador si solo estás probando puntualmente.

Este aviso **no debe aparecer nunca en producción**: en un despliegue real, sirve siempre la
aplicación con un certificado válido (ver
[HTTPS con Let's Encrypt](09-despliegue.md#https-con-lets-encrypt)).

### Un docente no ve ninguna sección

Si un docente entra correctamente pero el menú lateral aparece vacío (sin Partes, Sanciones,
Calendario, etc.), es casi siempre porque **no tiene ningún centro educativo accesible** en ese
momento: la mayoría de secciones requieren un centro seleccionado en la sesión.

Comprueba que el docente:

- Está dado de alta como docente del centro (ver
  [Añadir los docentes del curso académico](02-primeros-pasos.md#2-anadir-los-docentes-del-curso-academico-equipo-directivo)).
- Está asignado al menos a un grupo del curso académico activo, como tutor o como docente, **o**
  tiene el rol de administrador de ese centro — un docente sin ninguna de las dos cosas no tiene el
  centro entre sus centros accesibles y no puede seleccionarlo.
- El centro tiene un **curso académico activo**: sin curso activo, la oferta formativa y las
  asignaciones de grupo no existen todavía para ese centro.

## No funciona el correo

### No llegan los emails

1. Comprueba que `MAILER_DSN` está configurado con un transporte real (por defecto es
   `null://null`, que descarta todos los correos silenciosamente sin dar ningún error — ver
   [Notificaciones por email](06-notificaciones-y-email.md)).
2. Comprueba que el ajuste **Activar notificaciones automáticas** no está desactivado a nivel global, de
   centro o de docente (ver [Ajustes](07-ajustes.md#correo-electronico)).
3. Salvo el correo de restablecimiento de contraseña (que se envía al momento), los correos se
   **encolan** y los procesa un *worker* en segundo plano. Comprueba que el worker está en marcha
   (el contenedor `worker` en Docker, o el proceso que lanzan los scripts de arranque del binario
   nativo) y revisa la cola:

   ```bash
   php bin/console messenger:stats          # mensajes pendientes por cola
   php bin/console messenger:failed:show    # ver los envíos fallidos
   ```

   Ver [Correos en cola (Messenger)](10-operacion-y-mantenimiento.md#correos-en-cola-messenger)
   para más detalle, incluidos los reintentos automáticos.
4. Si los mensajes aparecen como fallidos, revisa las credenciales y el host del `MAILER_DSN`
   (usuario, contraseña, servidor SMTP o proveedor) y que el firewall del servidor permite la
   conexión saliente al puerto de envío.

## Problemas con la importación de estudiantes

### El CSV no crea estudiantes o faltan grupos

- Comprueba que el fichero contiene todas las **columnas obligatorias** con el nombre exacto que
  usa Séneca (`Estado Matrícula`, `Nº Id. Escolar`, `Nombre`, `Primer apellido`, `Segundo
  apellido`, `Unidad`): si falta alguna, la importación se cancela por completo antes de crear
  ningún registro. Ver
  [Formato del CSV de importación](02-primeros-pasos.md#formato-del-csv-de-importacion).
- La columna `Unidad` debe coincidir **exactamente** con el nombre de un grupo ya existente en la
  oferta formativa del curso activo (ver
  [Estructurar la oferta formativa](02-primeros-pasos.md#3-estructurar-la-oferta-formativa-del-curso-academico-equipo-directivo)):
  si el grupo no existe todavía, créalo antes de repetir la importación.
- El fichero debe estar en **UTF-8 o Windows-1252**; otras codificaciones pueden producir nombres o
  apellidos con caracteres extraños en lugar de un error explícito.
- Las filas con `Estado Matrícula` no vacío se omiten a propósito (representan bajas o matrículas no
  activas): si un estudiante no aparece tras importar, comprueba primero esa columna en el CSV
  original.

## Copias de seguridad y actualización

Estos dos procedimientos están documentados en detalle en
[Operación y mantenimiento](10-operacion-y-mantenimiento.md):

- [Copias de seguridad](10-operacion-y-mantenimiento.md#copias-de-seguridad) — cómo respaldar la
  base de datos según el tipo de despliegue (Docker/PostgreSQL o binario nativo/SQLite) y
  recomendaciones de retención.
- [Actualización](10-operacion-y-mantenimiento.md#actualizacion) — cómo actualizar a una nueva
  versión sin perder datos; las migraciones de base de datos se aplican automáticamente al
  arrancar.

Haz siempre una copia de seguridad **antes** de actualizar.
