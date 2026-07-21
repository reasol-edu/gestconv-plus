---
marp: true
theme: gestconv-cheatsheet
html: true
title: GestConv+ — Ficha rápida — Configurar un curso académico nuevo
author: GestConv+
lang: es
footer: 'v{{VERSION}} ({{PUB_DATE}}) · GestConv+'
---

<p class="kicker">GestConv+ · Ficha rápida · Equipo directivo</p>

# Configurar un curso académico nuevo

<div class="steps sin-capturas">

<div class="step">
  <span class="num">1</span>
  <div class="text"><p>Crear y activar el curso académico.</p></div>
</div>

<div class="step">
  <span class="num">2</span>
  <div class="text"><p>Añadir el profesorado (importación desde Séneca).</p></div>
</div>

<div class="step">
  <span class="num">3</span>
  <div class="text"><p>Importar el alumnado — crea solos los cursos y grupos.</p></div>
</div>

<div class="step">
  <span class="num">4</span>
  <div class="text"><p>Asignar las tutorías de cada grupo.</p></div>
</div>

<div class="step">
  <span class="num">5</span>
  <div class="text"><p>Importar quién imparte clase en cada grupo (opcional).</p></div>
</div>

<div class="step">
  <span class="num">6</span>
  <div class="text"><p>Definir los tramos horarios y el profesorado de guardia.</p></div>
</div>

<div class="step">
  <span class="num">7</span>
  <div class="text"><p>Revisar conductas, medidas, ubicaciones, métodos de comunicación y
  perfiles de comisión/orientación.</p></div>
</div>

</div>

<div class="nota">
  <p>Con los ficheros de Séneca a mano, todo el proceso lleva menos de media hora y se repite
  una vez al año, al abrir cada curso nuevo. Detalle completo en el capítulo «Preparar el
  curso académico» del manual.</p>
</div>

---

<p class="kicker">GestConv+ · Ficha rápida · Equipo directivo</p>

# 1. Crear y activar el curso académico

<div class="steps sin-capturas">

<div class="step">
  <span class="num">1</span>
  <div class="text">
    <p>Desde <strong>Centro educativo › Cursos académicos</strong>, crea el curso nuevo (por
    ejemplo, <strong>2026-2027</strong>) y márcalo como <strong>activo</strong>.</p>
    <p>Solo puede haber un curso activo por centro: es el curso de referencia para las altas de
    estudiantes, la oferta formativa, los partes y las sanciones. Los cursos anteriores no se
    pierden — quedan disponibles como histórico.</p>
    <img class="captura-escritorio" src="img/curso-nuevo-3-activado.png" alt="Listado de cursos académicos con 2026-2027 marcado como activo">
  </div>
</div>

</div>

---

<p class="kicker">GestConv+ · Ficha rápida · Equipo directivo</p>

# 2. Añadir el profesorado

<div class="steps sin-capturas">

<div class="step">
  <span class="num">1</span>
  <div class="text">
    <p>Desde <strong>Centro educativo › Docentes › Importar desde Séneca</strong>, sube el CSV
    que exporta Séneca (perfil de Dirección: <strong>Personal › Personal del centro › Exportar
    datos</strong>).</p>
    <p>La aplicación crea los docentes que no existan y añade al curso activo tanto los recién
    creados como los que ya existieran, sin modificar sus datos. Se aceptan ficheros en UTF-8 y
    en Windows-1252.</p>
    <img class="captura-escritorio" src="img/curso-nuevo-5-docentes-listado.png" alt="Listado de docentes del centro tras la importación">
  </div>
</div>

</div>

---

<p class="kicker">GestConv+ · Ficha rápida · Equipo directivo</p>

# 3. Importar el alumnado

<div class="steps sin-capturas">

<div class="step">
  <span class="num">1</span>
  <div class="text">
    <p>Desde <strong>Centro educativo › Estudiantes › Importar CSV</strong>, sube el CSV de
    Séneca (perfil de Dirección: <strong>Alumnado › Alumnado del centro › Exportar datos</strong>).
    Aquí está el mayor ahorro de tiempo: <strong>crea automáticamente los cursos y grupos</strong>
    que aparecen en el fichero — no hace falta preparar nada antes.</p>
    <p>Antes de confirmar nada, se muestra una <strong>vista previa</strong> con todo lo que se
    va a hacer; los cursos y grupos nuevos se pueden desmarcar si no interesa crearlos todavía.</p>
    <img class="captura-escritorio" src="img/curso-nuevo-7-estudiantes-vista-previa.png" alt="Vista previa de la importación de estudiantes, con los cursos y grupos nuevos que se van a crear">
  </div>
</div>

</div>

---

<p class="kicker">GestConv+ · Ficha rápida · Equipo directivo</p>

# 4. Asignar las tutorías de grupo

<div class="steps sin-capturas">

<div class="step">
  <span class="num">1</span>
  <div class="text">
    <p>Con el alumnado ya importado, desde <strong>Centro educativo › Oferta formativa</strong>
    selecciona cada grupo y asigna su tutor/a en el panel de detalle que aparece debajo.</p>
    <p>Esta asignación determina, entre otras cosas, qué docentes ven los partes y sanciones de
    su grupo.</p>
    <img class="captura-escritorio" src="img/curso-nuevo-11-tutoria-asignada.png" alt="Grupo 1ºESO A seleccionado, con Roberto Guerrero Campos asignado como tutor">
  </div>
</div>

</div>

---

<p class="kicker">GestConv+ · Ficha rápida · Equipo directivo</p>

# 5. Quién imparte clase en cada grupo (opcional)

<div class="steps sin-capturas">

<div class="step">
  <span class="num">1</span>
  <div class="text">
    <p>Desde <strong>Centro educativo › Docentes › Importar asignaciones a grupos</strong>,
    importa el CSV de Séneca (perfil de Dirección: <strong>Personal › Personal del centro ›
    Materia y grupos › Unidad: Cualquiera › Exportar datos</strong>). Es imprescindible haber
    importado antes el profesorado (paso 2).</p>
    <img class="captura-escritorio" src="img/curso-nuevo-13-asignaciones-listado.png" alt="Confirmación de asignaciones docente-grupo importadas correctamente">
  </div>
</div>

</div>

<div class="nota">
  <p>La aplicación funciona sin este paso, pero los docentes sin ningún grupo asignado no verán
  las sanciones de sus estudiantes en la sección Inicio.</p>
</div>

---

<p class="kicker">GestConv+ · Ficha rápida · Equipo directivo</p>

# 6. Definir los tramos horarios

<div class="steps sin-capturas">

<div class="step">
  <span class="num">1</span>
  <div class="text">
    <p>Desde <strong>Centro educativo › Tramos horarios</strong>, define los tramos en los que se
    organiza la jornada (1ª hora, recreo, 2ª hora…) y quién está de guardia en cada uno. El botón
    <strong>Añadir todos los días</strong> crea el mismo tramo en los cinco días lectivos a la
    vez, para no repetir el alta cinco veces cuando el horario es igual toda la semana.</p>
    <p>Al seleccionar un tramo se abre su formulario de edición, con un buscador de
    <strong>docentes de guardia</strong> que admite varios a la vez.</p>
    <img class="captura-escritorio" src="img/curso-nuevo-15-tramos-horarios.png" alt="Tablero de tramos horarios con dos docentes de guardia asignados al tramo del lunes">
  </div>
</div>

</div>

<div class="nota">
  <p>Hace falta esta configuración para que la sección «Guardias» funcione: sin tramos horarios
  no hay nada que asignar ni consultar.</p>
</div>

---

<p class="kicker">GestConv+ · Ficha rápida · Equipo directivo</p>

# 7. Revisar los catálogos del centro

<div class="steps sin-capturas">

<div class="step">
  <span class="num">1</span>
  <div class="text">
    <p><strong>Centro educativo › Conductas contrarias</strong> — 19 conductas por defecto,
    basadas en la normativa de convivencia escolar de Andalucía.</p>
  </div>
</div>

<div class="step">
  <span class="num">2</span>
  <div class="text">
    <p><strong>Centro educativo › Sanciones › Medidas disciplinarias</strong> — las medidas que
    se marcan al registrar una sanción.</p>
  </div>
</div>

<div class="step">
  <span class="num">3</span>
  <div class="text">
    <p><strong>Centro educativo › Ubicaciones</strong> — los lugares donde puede ocurrir un
    incidente, para el campo «Dónde sucedió» de los partes.</p>
  </div>
</div>

<div class="step">
  <span class="num">4</span>
  <div class="text">
    <p><strong>Centro educativo › Métodos de comunicación</strong> — cómo se notifica a las
    familias (llamada, correo, tutoría presencial…).</p>
  </div>
</div>

</div>

<div class="nota">
  <p>Los cuatro catálogos se configuran automáticamente con valores por defecto al dar de alta
  el centro, pensados para empezar a trabajar sin más preparación, y comparten la misma mecánica:
  cada elemento se puede activar o desactivar, reordenar y editar. Conviene revisarlos al menos
  una vez y adaptarlos al plan de convivencia del centro.</p>
</div>

---

<p class="kicker">GestConv+ · Ficha rápida · Equipo directivo</p>

# Resultado: el centro, listo para trabajar

<div class="steps sin-capturas">

<div class="step">
  <span class="num">✓</span>
  <div class="text">
    <p>Con los pasos anteriores, el curso <strong>2026-2027</strong> queda activo, con su
    profesorado, alumnado, oferta formativa, tutorías, tramos horarios y catálogos revisados.
    Solo falta asignar los perfiles de comisión de convivencia y orientación (ver el capítulo
    «Administrar el centro educativo» del manual).</p>
    <img class="captura-escritorio" src="img/curso-nuevo-14-centro-listo.png" alt="Panel de Centro educativo con el curso 2026-2027 activo y todas las secciones disponibles">
  </div>
</div>

</div>
