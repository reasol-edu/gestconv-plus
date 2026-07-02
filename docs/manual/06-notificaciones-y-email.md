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

Con el correo activo, la aplicación envía actualmente dos tipos de mensaje, ambos transaccionales (no se pueden desactivar individualmente):

- **Verificación de email**: cuando un docente añade o cambia su dirección en su perfil, recibe un enlace para confirmarla.
- **Restablecimiento de contraseña**: enlace de un solo uso con caducidad de 1 hora, solicitado desde la pantalla de acceso.

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
