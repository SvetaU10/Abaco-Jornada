# Plan

Orden de Pablo, 9 de octubre de 2026: dejar los acuerdos por escrito, corregir los puntos críticos y probar los intentos de elusión junto a incidencias técnicas reales.

Responsable del desarrollo: Svetlana. La revisión es de Pablo.

Una tarea está terminada cuando la regla está en el código, hay una prueba que falla si se rompe, y este plan y [pruebas.md](pruebas.md) dicen el resultado.

## Crítico

Corregir antes de usar registros reales.

| Tarea | Hecho cuando | Estado |
| --- | --- | --- |
| Documentar alcance, reglas, decisiones, instalación y pruebas, y enlazarlas desde el README | Otra persona arranca el proyecto y ejecuta las pruebas solo con el repositorio | Hecho en esta carpeta. `AGENTS.md` y `CLAUDE.md` siguen siendo la guía genérica de Laravel, no el plan |
| Quitar la cola que guarda formularios sin conexión y los reenvía sola | Sin red, el fichaje no se guarda. El texto es «No guardado: sin conexión». Al volver se puede declarar la incidencia con motivo, autor y momento | Hecho. La cola ya no existe. La declaración es `jornada.incidencia` |
| Impedir tramos solapados y separar el tiempo ya confirmado del que sigue abierto | Una corrección y una reunión no suman dos veces el mismo intervalo. Hoy y el registro/CSV enseñan el mismo criterio | Hecho |
| Releer el tramo dentro de la operación que guarda una corrección | Dos cambios seguidos no pueden dejar el inicio después del fin | Hecho |
| Aislar cuentas de prueba y el borrado del fichaje de hoy | Esas funciones no existen cuando hay datos reales | Hecho. Solo en local y en las pruebas |

## Mejora

| Tarea | Hecho cuando | Estado |
| --- | --- | --- |
| Un cambio de horario solo excluye los avisos de las fechas afectadas | Los avisos de otros días siguen contando | Hecho. Solo desde el día en que vale el horario nuevo |
| Guardar quién declara una salida y cuándo, sin que una corrección borre esa historia | El cierre tiene autor y momento, además de la hora trabajada | Hecho. `cerrado_por` y `cerrado_at` no se tocan al corregir |
| Histórico con filtro o páginas | No se corta el registro en 60 jornadas sin decirlo | Hecho. 60 por página, con fechas desde y hasta |
| Añadir a las pruebas los fallos de la revisión del 9 de octubre | [pruebas.md](pruebas.md) marca cada uno como cubierto | Pendiente |

## Revisar

| Tarea | Hecho cuando | Estado |
| --- | --- | --- |
| Dos horarios con la misma fecha de vigencia | Está escrito cuál prevalece y hay una prueba | Pendiente |
| Texto «No es una hora extra» y cuándo un año de calendario está completo | El texto no clasifica el exceso. La regla del año está definida | Pendiente |
| Explicar no cierra una salida ni borra un olvido confirmado | Explicación, corrección y resolución son acciones distintas | Pendiente |
| Márgenes de 8 avisos, 7 días y cierre de ayer | Estar por debajo del umbral no da por bueno un día sin cerrar | Parcial. La prueba de los 8 avisos existe; falta el resto de elusiones |
| Desconexión, cambio de cuenta, sesión caducada, copia restaurada y dos ediciones a la vez | Probado en el entorno elegido, no solo en la base de pruebas | Pendiente |
