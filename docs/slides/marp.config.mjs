// Configuración de Marp CLI para la presentación (ver el target "slides" del Makefile).
//
// Marp convierte por defecto cada salto de línea de un párrafo en un <br>. Las diapositivas se redactan con
// las líneas partidas a ~80 columnas (como el resto de Markdown del repositorio), así que sin esto cada
// línea partida se vería como un salto y las diapositivas crecerían mucho en vertical.
export default {
  options: {
    markdown: { breaks: false },
  },
};
