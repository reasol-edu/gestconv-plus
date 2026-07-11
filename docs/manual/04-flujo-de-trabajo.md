# Flujo de trabajo

El flujo habitual al inicio de cada curso académico sigue estos pasos. Los partes de convivencia, en cambio, se registran de forma continua durante todo el curso.

## 1 — Configurar el curso

El administrador de centro crea o activa el curso académico en **Centro educativo › Cursos académicos**. Si el centro es nuevo, se crean las 19 conductas contrarias por defecto; se pueden revisar y ajustar en **Conductas contrarias**.

## 2 — Estructurar la oferta formativa

Se definen los programas de estudio (enseñanzas), los niveles dentro de cada enseñanza y los grupos. Si ya existía en un curso o centro anterior, puede importarse desde un fichero JSON exportado previamente desde la propia aplicación (no es un fichero que se descargue de Séneca).

## 3 — Añadir docentes y grupos

Se importan los docentes desde el CSV de Séneca y se les asigna a los grupos como tutores/as o docentes.

## 4 — Dar de alta a los estudiantes

Se importan los estudiantes desde el CSV de Séneca. El importador rellena automáticamente nombre, apellidos, grupo, tutores, teléfonos y observaciones si las columnas correspondientes están presentes en el fichero.

## 5 — Registrar partes de convivencia

A lo largo del curso, cualquier docente puede registrar partes de convivencia desde la sección **Partes** del menú lateral o desde el botón de acceso rápido del inicio. Si en el incidente participaron varios estudiantes, se crea un parte por cada uno con los mismos datos, y al guardar una pantalla de confirmación ofrece notificar a las familias de inmediato. El parte queda vinculado al estudiante, el grupo y el docente que lo registra. Los tutores de grupo y los administradores de centro pueden consultar los partes de sus grupos o de todo el centro, respectivamente.

## 6 — Notificar a las familias

Antes de poder tramitar una sanción, la familia debe ser informada del parte. La sección **Notificaciones** del menú lateral muestra la cola de partes y sanciones pendientes de comunicar, y permite registrar cada intento (método, fecha y hora, descripción y resultado). Un parte **sin comunicación exitosa no puede incorporarse a una sanción**. Consulta [Notificaciones](05-secciones-de-la-aplicacion.md#notificaciones) para el detalle del formulario, el historial y quién puede notificar según los ajustes del centro.

## 7 — Registrar sanciones

Con el parte ya notificado, la comisión de convivencia o el equipo directivo registra la sanción desde la sección [Sanciones](05-secciones-de-la-aplicacion.md#sanciones), incorporando uno o varios partes del estudiante y aplicando las medidas disciplinarias configuradas por el centro. La sanción también debe **notificarse a la familia** para entrar en vigor: hasta entonces no aparece en el [calendario ni en el modo tablón](05-secciones-de-la-aplicacion.md#calendario), aunque tenga fechas de vigencia asignadas.

## 8 — Hacer el seguimiento

Una vez notificadas, las sanciones con fechas de vigencia aparecen en el [calendario](05-secciones-de-la-aplicacion.md#calendario) y, si el centro lo usa, en el modo tablón de la sala de profesorado. Para consultar la trayectoria completa de un estudiante —sus partes, sanciones y el estado de notificación de cada uno— está la [ficha del estudiante](05-secciones-de-la-aplicacion.md#ficha-del-estudiante), accesible desde el buscador global (Ctrl+K) y desde los listados.
