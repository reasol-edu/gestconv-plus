# Introducción

**GestConv+** es una aplicación web para gestionar las conductas contrarias a la convivencia en
centros de Educación Secundaria Obligatoria, Bachillerato y Formación Profesional. Centraliza el
registro de partes de convivencia, la tramitación de sanciones, la comunicación con las familias y
el seguimiento del historial de cada estudiante.

## Multicentro

Un mismo servidor puede alojar **varios centros educativos** con datos completamente separados:
partes, sanciones, estudiantes, oferta formativa y configuración de cada centro son independientes
entre sí.

Un docente puede estar vinculado a varios centros (por ejemplo, si imparte clase en más de uno). Al
entrar en la aplicación, si tiene acceso a más de un centro se le muestra un selector para elegir con
cuál trabajar en esa sesión; puede cambiar de centro en cualquier momento desde el menú lateral. Los
administradores globales tienen acceso a todos los centros dados de alta en el servidor.

## Cómo usar este manual

El manual está organizado siguiendo el orden natural de puesta en marcha de la aplicación:

- Los capítulos [Instalación y requisitos](01-instalacion-y-requisitos.md) y
  [Despliegue](09-despliegue.md) son para quien instala y mantiene el servidor.
- [Primeros pasos](02-primeros-pasos.md) y [Flujo de trabajo](04-flujo-de-trabajo.md) guían la
  configuración inicial de un centro, curso a curso.
- [Roles y permisos](03-roles-y-permisos.md) y [Secciones de la aplicación](05-secciones-de-la-aplicacion.md)
  son la referencia del día a día para el profesorado y el equipo directivo.
- [Notificaciones por email](06-notificaciones-y-email.md), [Ajustes](07-ajustes.md) y
  [Comandos de consola](08-comandos-de-consola.md) cubren aspectos de configuración más específicos.
- [Operación y mantenimiento](10-operacion-y-mantenimiento.md) y
  [Resolución de problemas](11-resolucion-de-problemas.md) están pensados para quien administra el
  servidor una vez la aplicación ya está en marcha.
- El [Glosario](12-glosario.md) recoge los términos propios de la aplicación y de la normativa de
  convivencia escolar andaluza que aparecen a lo largo del manual.

No hace falta leerlo de principio a fin: cada capítulo se puede consultar de forma independiente, y
los enlaces entre secciones llevan directamente al contexto necesario.

## Sobre el proyecto

GestConv+ es software libre, publicado bajo licencia [AGPL-3.0](http://www.gnu.org/licenses/agpl.html)
y desarrollado con [Symfony](https://symfony.com). El código fuente, las incidencias y las nuevas
versiones se publican en
**[github.com/reasol-edu/gestconv-plus](https://github.com/reasol-edu/gestconv-plus)**.

Forma parte del proyecto de innovación educativa REASOL (PIN-219/23 y PIN-354/24), financiado
por la Consejería de Desarrollo Educativo y Formación Profesional de la Junta de Andalucía.
