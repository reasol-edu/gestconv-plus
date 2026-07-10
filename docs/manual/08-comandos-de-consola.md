# Comandos de consola

Todos los comandos se ejecutan con `php bin/console <comando>` (o, en el paquete distribuido para
Windows/Ubuntu, con el binario de FrankenPHP: `frankenphp php-cli bin/console <comando>`). Solo
existen los tres comandos de esta página bajo el espacio de nombres `app:`; puedes comprobarlo en
cualquier momento con `php bin/console list app`.

## app:setup

```
php bin/console app:setup [--no-force-password-change]
```

Inicializa la aplicación con datos de demostración **si la base de datos está completamente
vacía** (si no existe ningún docente todavía). Si ya hay al menos un docente registrado, el comando
no hace nada y termina sin error — por eso es seguro incluirlo en el arranque automático de la
aplicación (lo hacen los scripts `start.sh`/`start.ps1`/`start.bat` del paquete distribuido, ver
[Despliegue](09-despliegue.md)) sin riesgo de duplicar datos en arranques posteriores.

Cuando sí se ejecuta, crea:

- Un centro educativo de demostración (código `23999999`, nombre «IES Test», ciudad «Linares») con
  un curso académico activo nombrado con el año en curso (por ejemplo, «2026-2027»).
- Las conductas contrarias a la convivencia, medidas disciplinarias y métodos de comunicación por
  defecto del centro (ver [Secciones de la aplicación](05-secciones-de-la-aplicacion.md)).
- Un docente administrador global con usuario `admin` y contraseña `admin`, con el cambio de
  contraseña obligatorio en el primer inicio de sesión (ver
  [Secciones de la aplicación](05-secciones-de-la-aplicacion.md#forzar-cambio-de-contrasena)): la
  cuenta queda confinada a la pantalla de cambio de contraseña hasta que se establece una nueva.
  La opción `--no-force-password-change` omite esta obligación y deja la contraseña `admin` activa
  sin restricciones.

> Si usas `--no-force-password-change`, cambia la contraseña del usuario `admin` manualmente
> inmediatamente después del primer arranque en cualquier entorno accesible desde fuera de tu
> equipo.

## app:create-educational-centre

```
php bin/console app:create-educational-centre [código] [nombre] [ciudad]
```

Crea un nuevo centro educativo. Los tres argumentos son opcionales: si se omite alguno, el comando
lo pregunta de forma interactiva.

| Argumento | Descripción |
|---|---|
| `código` | Código de centro (por ejemplo, `23700281`) |
| `nombre` | Nombre del centro |
| `ciudad` | Localidad |

El centro se crea sin curso académico activo ni oferta formativa: ambos se configuran después desde
la aplicación (ver [Primeros pasos](02-primeros-pasos.md)).

## app:create-admin

```
php bin/console app:create-admin <usuario> [contraseña] [--no-force-password-change]
```

Crea una cuenta de docente con privilegios de **administrador global** (acceso a todos los centros
del servidor). El argumento `usuario` es obligatorio; si se omite `contraseña`, el comando la pide
de forma interactiva sin mostrarla en pantalla.

Si el nombre de usuario ya existe, el comando falla con un error y no modifica la cuenta existente.

Por defecto, la cuenta creada tiene el cambio de contraseña obligatorio en el primer inicio de
sesión (ver [Secciones de la aplicación](05-secciones-de-la-aplicacion.md#forzar-cambio-de-contrasena)).
La opción `--no-force-password-change` omite esta obligación y deja la contraseña indicada activa
sin restricciones.

Es la vía recomendada para crear administradores adicionales o para recuperar el acceso si se ha
perdido la contraseña del único administrador (ver
[Resolución de problemas](11-resolucion-de-problemas.md)).
