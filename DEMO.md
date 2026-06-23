# Datos de demostración

Este fichero describe el conjunto de datos que cargan las fixtures (`AppFixtures`).
Se genera con `php bin/console doctrine:fixtures:load` (o los scripts `demo.*` del binario).

---

## Credenciales

| Usuario | Contraseña | Rol |
|---|---|---|
| `admin` | `admin` | Administrador global (sin centro) |
| Cualquier docente de centro | `ejemplo` | Según el rol asignado en su centro |

---

## Centro 1 — IES Ada Lovelace · Linares · `23006123`

**Curso activo:** 2025-2026

### Equipo directivo (admins de centro)

| Usuario | Nombre | Nota |
|---|---|---|
| `rafael.exposito` | Rafael Expósito Moreno | También admin global |
| `carmen.diaz` | Carmen Díaz Jiménez | |

### Claustro (30 docentes en total)

Todos con contraseña `ejemplo`. Los docentes son asignados automáticamente como tutores y co-docentes de los grupos.

<details>
<summary>Ver lista completa</summary>

| Usuario | Nombre |
|---|---|
| `francisco.molina` | Francisco Molina Ruiz |
| `isabel.lozano` | Isabel Lozano Herrera |
| `maria.garcia` | María Dolores García Fernández |
| `diego.romero` | Diego Romero Vega |
| `manuel.perez` | Manuel Pérez Blanco |
| `roberto.guerrero` | Roberto Guerrero Campos |
| `beatriz.alonso` | Beatriz Alonso Serrano |
| `rodrigo.fuentes` | Rodrigo Fuentes Parra |
| `elena.caballero` | Elena Caballero Ruiz |
| `julio.medina` | Julio Medina Torres |
| `sofia.delgado` | Sofía Delgado Iglesias |
| `marcos.herrero` | Marcos Herrero Vidal |
| `alberto.cabrera` | Alberto Cabrera García |
| `nuria.lopez` | Nuria López Morales |
| `javier.ortega` | Javier Ortega Bravo |
| `anabelen.castro` | Ana Belén Castro Fuentes |
| `tomas.vazquez` | Tomás Vázquez Acosta |
| `rosamaria.serrano` | Rosa María Serrano Díaz |
| `fernando.ibanez` | Fernando Ibáñez Cano |
| `marta.ramos` | Marta Ramos Palacios |
| `sergio.gallego` | Sergio Gallego Nieto |
| `veronica.mora` | Verónica Mora Espinosa |
| `pablo.aguilar` | Pablo Aguilar Blanco |
| `concepcion.munoz` | Concepción Muñoz Aranda |
| `alvaro.suarez` | Álvaro Suárez Paredes |
| `patricia.rubio` | Patricia Rubio Fernández |
| `luis.carrasco` | Luis Carrasco Reyes |
| `sandra.dominguez` | Sandra Domínguez Orozco |

</details>

### Estructura académica

#### Enseñanza: ESO

| Curso | Grupo A | Grupo B |
|---|---|---|
| 1º ESO | `1ºESO A` — 28 alumnos | `1ºESO B` — 27 alumnos |
| 2º ESO | `2ºESO A` — 28 alumnos | `2ºESO B` — 27 alumnos |
| 3º ESO | `3ºESO A` — 28 alumnos | `3ºESO B` — 27 alumnos |
| 4º ESO | `4ºESO A` — 28 alumnos | `4ºESO B` — 27 alumnos |

#### Enseñanza: Bachillerato

| Curso | Grupo |
|---|---|
| 1º Bachillerato | `1ºBachillerato` — 22 alumnos |
| 2º Bachillerato | `2ºBachillerato` — 22 alumnos |

#### Enseñanza: CFGS Desarrollo de Aplicaciones Web

| Curso | Grupo |
|---|---|
| 1º DAW | `1ºDAW` — 24 alumnos |
| 2º DAW | `2ºDAW` — 24 alumnos |

**Totales:** 12 grupos · 312 alumnos

---

## Centro 2 — IES Monterrubio · Utrera · `41017845`

**Curso activo:** 2025-2026

### Equipo directivo (admins de centro)

| Usuario | Nombre | Nota |
|---|---|---|
| `mariajose.alvarez` | María José Álvarez García | También admin global |
| `pedro.fernandez` | Pedro Antonio Fernández Rubio | |

### Claustro (30 docentes en total)

<details>
<summary>Ver lista completa</summary>

| Usuario | Nombre |
|---|---|
| `rosario.soto` | Rosario Soto Merino |
| `dolores.reyes` | Dolores Reyes Álvarez |
| `antonia.guzman` | Antonia Guzmán Osuna |
| `ignacio.crespo` | Ignacio Crespo Leal |
| `piedad.torres` | Piedad Torres Velázquez |
| `vicente.roldan` | Vicente Roldán Camacho |
| `carmenrosa.marin` | Carmen Rosa Marín Espejo |
| `josefa.naranjo` | Josefa Naranjo Hidalgo |
| `remedios.calvo` | Remedios Calvo Durán |
| `bartolome.morales` | Bartolomé Morales Cabello |
| `francisca.giron` | Francisca Girón Padilla |
| `sebastian.lara` | Sebastián Lara Nieto |
| `encarnacion.baena` | Encarnación Baena Vilches |
| `manuela.criado` | Manuela Criado Arroyo |
| `demetrio.gallardo` | Demetrio Gallardo Cruz |
| `amelia.fuentes` | Amelia Fuentes Olea |
| `isidoro.bueno` | Isidoro Bueno Salas |
| `remedios.ortiz` | Remedios Ortiz Pedrera |
| `alfonso.serrano` | Alfonso Serrano Rico |
| `montserrat.cobo` | Montserrat Cobo Rivas |
| `gonzalo.torres` | Gonzalo Torres Jurado |
| `esperanza.ruiz` | Esperanza Ruiz Calero |
| `horacio.lopez` | Horacio López Bravo |
| `natividad.moreno` | Natividad Moreno Navarro |
| `dionisio.garcia` | Dionisio García Blanco |
| `rosalia.campos` | Rosalía Campos Vega |
| `teodoro.herrero` | Teodoro Herrero Reina |
| `milagros.jimenez` | Milagros Jiménez Villar |

</details>

### Estructura académica

#### Enseñanza: ESO

| Curso | Grupo A | Grupo B |
|---|---|---|
| 1º ESO | `1ºESO A` — 26 alumnos | `1ºESO B` — 26 alumnos |
| 2º ESO | `2ºESO A` — 26 alumnos | `2ºESO B` — 26 alumnos |
| 3º ESO | `3ºESO A` — 26 alumnos | `3ºESO B` — 26 alumnos |
| 4º ESO | `4ºESO A` — 26 alumnos | `4ºESO B` — 26 alumnos |

#### Enseñanza: Bachillerato

| Curso | Grupo |
|---|---|
| 1º Bachillerato | `1ºBachillerato` — 20 alumnos |
| 2º Bachillerato | `2ºBachillerato` — 20 alumnos |

**Totales:** 10 grupos · 248 alumnos

---

## Resumen global

| | IES Ada Lovelace | IES Monterrubio | Total |
|---|---|---|---|
| Docentes | 30 | 30 | 61 (+ `admin`) |
| Grupos ESO | 8 | 8 | 16 |
| Grupos Bachillerato | 2 | 2 | 4 |
| Grupos CFGS | 2 | — | 2 |
| **Grupos total** | **12** | **10** | **22** |
| Alumnos ESO | 220 | 208 | 428 |
| Alumnos Bachillerato | 44 | 40 | 84 |
| Alumnos CFGS | 48 | — | 48 |
| **Alumnos total** | **312** | **248** | **560** |
