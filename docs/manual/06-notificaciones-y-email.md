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

Dos ajustes adicionales, **Enviar parte adjunto al correo** y **Enviar sanción adjunta al correo**
(ver [Ajustes](07-ajustes.md#avisos-por-correo), desactivados por defecto), añaden el PDF
correspondiente como adjunto: el primero a cualquiera de los seis avisos de parte, el segundo al
aviso de sanción notificada. Solo afectan al correo enviado — no al
[registro de avisos por correo](#registro-de-avisos-por-correo), que sigue registrando el envío
igual que sin el adjunto.

## Prescripción automática de partes sin notificar

Una tarea programada diaria revisa, para cada centro, los partes que todavía no se han comunicado a
la familia y marca como prescritos los que llevan más días sin notificar que el valor configurado en
el ajuste **Días para la prescripción automática** (ver [Ajustes](07-ajustes.md#notificaciones-a-familias),
14 por defecto, 0 para desactivarla). El plazo se cuenta desde la fecha en la que ocurrió el
incidente. Si el aviso de «Parte prescrito» está activado, se envía igual que si lo hubiera marcado
un administrador manualmente, pero sin nombrar a ningún docente como autor de la acción.

## Aviso de prescripción próxima

Otra tarea programada diaria, independiente de la anterior, avisa con antelación de los partes que
están a punto de prescribir automáticamente. Para cada docente que puede notificar partes (según el
ajuste **Quién notifica los partes de convivencia**), revisa los que le corresponden y todavía no se
han comunicado a la familia; si alguno prescribirá en el número de días configurado en **Aviso de
prescripción próxima** (ver [Ajustes](07-ajustes.md#notificaciones-a-familias), 7 por defecto, 0 para
desactivarlo, con posibilidad de un valor distinto por docente) o menos, le envía un único correo
con el listado completo de esos partes.

Este aviso no se envía en el momento en que un parte se marca como prescrito (ni manualmente ni por
la prescripción automática), y nunca incluye partes ya prescritos: solo avisa mientras todavía se
puede evitar la prescripción notificando a la familia.

## Registro de avisos por correo

Cada envío de los avisos de partes y sanciones descritos arriba queda registrado —tanto si se
entrega correctamente como si falla— en un historial visible desde **Centro educativo › Registro de
avisos por correo**, accesible solo para los administradores del centro. Cada entrada muestra la
fecha, el destinatario, el tipo de evento, el asunto y el resultado (entregado o fallido, con el
motivo del error en este último caso). El listado pagina y puede filtrarse por texto libre
(destinatario o asunto), tipo de evento, resultado y rango de fechas, con accesos rápidos para las
últimas 24 horas, la última semana o el último mes.

El registro se activa o desactiva con el ajuste **Registrar los avisos por correo**, a nivel global
o de centro (ver [Ajustes](07-ajustes.md#notificaciones-a-familias)); activado por defecto. Al
desactivarlo, los avisos se siguen enviando con normalidad, simplemente no se guarda constancia de
ellos. Este historial no incluye los correos de verificación de email ni de restablecimiento de
contraseña, que no son configurables y no tienen registro propio.

Las entradas se purgan automáticamente pasados los días configurados en el ajuste **Retención de
los registros** (90 por defecto, limpieza semanal los domingos a las 3:00; ver
[Ajustes](07-ajustes.md#notificaciones-a-familias)). Este mismo ajuste, exclusivamente global,
controla también la retención del
[registro de actividad](05-secciones-de-la-aplicacion.md#registro-de-actividad).

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
