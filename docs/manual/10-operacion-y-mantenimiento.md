# Operación y mantenimiento

## Copias de seguridad

Lo único imprescindible de respaldar es la **base de datos**: contiene todos los partes, sanciones, comunicaciones, estudiantes y ajustes. La carpeta `data/var` (caché, logs y sesiones) se regenera sola y no necesita copia.

### Despliegue con Docker (PostgreSQL)

Genera un volcado consistente sin parar la aplicación:

```bash
docker compose exec database pg_dump -U gestconv -Fc gestconv > gestconv-$(date +%F).dump
```

Para restaurarlo sobre una base de datos vacía:

```bash
docker compose exec -T database pg_restore -U gestconv -d gestconv --clean --if-exists < gestconv-2026-07-02.dump
```

### Binario nativo (SQLite)

La base de datos es el fichero `data/gestconv-plus.db`. Puedes copiarlo en caliente de forma segura con:

```bash
sqlite3 data/gestconv-plus.db ".backup 'gestconv-$(date +%F).db'"
```

o simplemente copiar el fichero con la aplicación parada.

### Recomendaciones

- Automatiza la copia con una tarea programada (cron) **diaria** y conserva varias generaciones (por ejemplo, 7 diarias y 4 semanales).
- Guarda las copias en una máquina distinta de la del servidor.
- Al contener datos personales de menores, **cifra las copias** o almacénalas en un soporte cifrado.
- Prueba la restauración de vez en cuando: una copia que nunca se ha restaurado no es una copia.
- Haz siempre una copia manual **antes de actualizar** la aplicación.

## Correos en cola (Messenger)

Los correos automáticos (verificación de dirección de email) no se envían durante la petición web: se **encolan en la base de datos** y los procesa un *worker* en segundo plano.

- **Docker**: el servicio `worker` de `compose.yaml` los procesa automáticamente.
- **Binario nativo**: los scripts de arranque (`start.sh`, `start.bat`, `start.ps1` y el servicio de `install-ubuntu.sh`) lanzan el worker junto con la aplicación.

La excepción es el correo de **restablecimiento de contraseña**, que se envía de forma síncrona durante la petición por ser urgente (el token caduca en 1 hora).

Si un envío falla, se reintenta hasta 3 veces con esperas crecientes; agotados los reintentos pasa a la cola de fallidos. Comandos útiles:

```bash
php bin/console messenger:stats          # mensajes pendientes por cola
php bin/console messenger:failed:show    # ver los envíos fallidos
php bin/console messenger:failed:retry   # reintentarlos
```

(En el binario nativo, usa `./frankenphp php-cli bin/console …` en lugar de `php bin/console …`.)

## Actualización

1. Haz una **copia de seguridad** de la base de datos (ver arriba).
2. Actualiza según el tipo de despliegue:
   - **Docker**: `docker compose pull && docker compose up -d`.
   - **Binario nativo**: sigue los pasos de [Actualizar a una nueva versión](09-despliegue.md#actualizar-a-una-nueva-version).
3. Las **migraciones de base de datos se aplican automáticamente** al arrancar la nueva versión; no hay que ejecutar nada a mano.
4. Comprueba que la aplicación arranca y revisa el [CHANGELOG](https://github.com/reasol-edu/gestconv-plus/blob/main/CHANGELOG.md) por si alguna novedad requiere ajustar la configuración.

## Protección de datos (RGPD)

La aplicación trata datos personales de menores (identidad, grupo, incidencias de convivencia y comunicaciones con las familias), por lo que el centro educativo —o la entidad que la aloje— actúa como **responsable del tratamiento** y debe desplegarla conforme a su política de protección de datos.

Medidas que la aplicación aporta de serie:

- **Acceso restringido**: solo docentes registrados pueden entrar, y cada perfil ve únicamente lo que le corresponde (ver [Roles y permisos](03-roles-y-permisos.md)). Los datos de cada centro están separados.
- **Registro de actividad**: con `APP_LOG=true` (valor por defecto) queda traza de las acciones de los usuarios, incluida la suplantación de identidad por administradores. Las entradas se purgan automáticamente pasados `APP_LOG_RETENTION_DAYS` días (90 por defecto, limpieza semanal los domingos a las 3:00).
- **Contraseñas** almacenadas con algoritmos de *hashing* modernos, y verificación TLS activada por defecto en la autenticación externa contra iSéneca.

Responsabilidades del centro al desplegar:

- Servir la aplicación **siempre sobre HTTPS** (ver [Despliegue](09-despliegue.md#https-con-lets-encrypt)).
- Limitar el número de administradores y revisar el registro de actividad periódicamente.
- Cifrar o custodiar adecuadamente las copias de seguridad y definir su plazo de conservación.
- Atender los derechos de acceso, rectificación y supresión: los datos de un estudiante pueden consultarse y corregirse desde su ficha, y la eliminación de un estudiante, curso o centro borra en cascada sus datos asociados.
