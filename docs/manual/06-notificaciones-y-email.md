# Notificaciones por email

!!! note "No confundir con la sección «Notificaciones» de la aplicación"
    Este capítulo trata de los **correos electrónicos automáticos** que envía la aplicación
    (verificación de dirección, restablecimiento de contraseña). El registro de las
    **comunicaciones con las familias** sobre partes y sanciones se hace desde la sección
    **Notificaciones** del menú lateral y se explica en
    [Secciones de la aplicación](05-secciones-de-la-aplicacion.md#notificaciones).

## Activar el correo

El envío de correo está **desactivado por defecto** (`MAILER_DSN=null://null`: los mensajes se descartan). Para activarlo, define en el entorno (o en `.env.local`):

```bash
# Servidor SMTP propio o de tu proveedor
MAILER_DSN=smtp://usuario:clave@servidor:587
# Dirección remitente de los correos automáticos
MAILER_FROM=no-responder@tucentro.example.org
```

Configura también `DEFAULT_URI` con la URL pública de la aplicación: es la que se usa para construir los enlaces incluidos en los correos.

Con el correo activo, la aplicación envía dos tipos de mensaje transaccionales, que no se pueden desactivar:

- **Verificación de email**: cuando un docente añade o cambia su dirección en su perfil, recibe un enlace para confirmarla.
- **Restablecimiento de contraseña**: enlace de un solo uso con caducidad de 1 hora, solicitado desde la pantalla de acceso.

Además, envía los avisos sobre partes y sanciones descritos a continuación, que sí son configurables.

## Avisos de partes y sanciones

Cada evento relevante de un parte o una sanción puede generar un correo. A diferencia de los dos
mensajes anteriores, estos avisos están **desactivados por defecto** y se activan uno a uno desde
[Ajustes](07-ajustes.md#avisos-por-correo), a nivel global o de centro,
eligiendo en cada caso si se notifica a nadie, al docente que registró el elemento, al tutor/a del
grupo o a ambos:

- **Parte registrado, notificado a la familia, modificado, eliminado, prescrito o incorporado a una
  sanción**: seis avisos independientes, uno por evento. El de «modificado» cubre cualquier edición
  del parte salvo marcarlo como prescrito, que tiene su propio aviso. El de «prescrito» se envía
  tanto si un administrador marca el parte manualmente como si prescribe de forma automática (ver
  más abajo).
- **Sanción notificada a la familia**: se avisa al docente que registró cada uno de los partes
  incorporados a la sanción y/o al tutor/a del grupo.
- **Parte sancionable**: cuando un parte queda notificado a la familia y todavía no está prescrito
  ni incorporado a una sanción, puede avisarse a todos los docentes con el perfil de comisión de
  convivencia del centro.

Cada correo enlaza directamente al parte o la sanción correspondiente (salvo el de eliminación, ya
que el elemento deja de existir) e incluye el nombre de quien realizó la acción, salvo el de
prescripción automática, que no tiene un docente asociado.

## Prescripción automática de partes sin notificar

Una tarea programada diaria revisa, para cada centro, los partes que todavía no se han comunicado a
la familia y marca como prescritos los que llevan más días sin notificar que el valor configurado en
el ajuste **Días para la prescripción automática** (ver [Ajustes](07-ajustes.md#notificaciones-a-familias),
14 por defecto, 0 para desactivarla). El plazo se cuenta desde la fecha en la que ocurrió el
incidente. Si el aviso de «Parte prescrito» está activado, se envía igual que si lo hubiera marcado
un administrador manualmente, pero sin nombrar a ningún docente como autor de la acción.

### Usar una cuenta de Gmail

Para pruebas o centros pequeños puede usarse una cuenta de Gmail con una
[contraseña de aplicación](https://support.google.com/accounts/answer/185833):

```bash
MAILER_DSN=gmail://USUARIO:CONTRASEÑA_DE_APLICACION@default
```

Gmail impone límites de envío diarios y puede bloquear la cuenta ante usos intensivos, así que **no es recomendable en producción**: usa el SMTP institucional o un proveedor transaccional.

## Envío asíncrono

Los correos de verificación no se envían durante la petición web: se encolan y los procesa un *worker* en segundo plano (el contenedor `worker` en Docker, o el proceso que lanzan los scripts de arranque del binario nativo). El correo de restablecimiento de contraseña es la excepción: se envía al momento por la urgencia del token.

Los detalles de la cola, los reintentos y los comandos de diagnóstico están en
[Operación y mantenimiento](10-operacion-y-mantenimiento.md#correos-en-cola-messenger).
