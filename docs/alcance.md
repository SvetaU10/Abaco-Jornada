# Alcance

Fecha de esta descripción: 9 de octubre de 2026.

Jornada es una aplicación web. La persona entra con su cuenta y el servidor confirma cada fichaje. No es un programa de escritorio ni un instalador.

## Incluye

- Entrada, pausa, vuelta y salida. La pausa no suma como tiempo trabajado.
- Inicio ya en curso («Ya estaba trabajando») y reunión que conserva la hora prevista del horario.
- Día anterior sin cerrar: se pregunta la hora de salida antes de abrir el día nuevo.
- Equipo encendido después de la hora de salida: no alarga la jornada. La persona puede decir que sigue.
- Trabajo fuera del equipo, con la hora de inicio y de fin que indica la persona.
- Horario en el alta. Calendario de festivos del centro de Madrid para toda la plantilla.
- Registro propio, copia CSV y corrección de una hora. La hora anterior se conserva, con quién la cambió y cuándo.
- Consulta del registro de otra persona con un motivo, y ese acceso queda escrito.
- Avisos por cierres sin completar. Por debajo de 8 en 28 días el registro no cambia de estado.
- Tres papeles: trabajadora, responsable y dirección. La dirección solo consulta. La baja cierra el acceso y no borra las jornadas.

## Fuera

- Geolocalización, biometría, capturas de pantalla y vigilancia de actividad.
- Módulo de horas extra y autorización para seguir después del horario. El exceso se muestra; Ábaco decide cómo clasificarlo.
- Borrado general de registros.
- Instalador de escritorio. Una hora hecha en otro ordenador se anota en esta aplicación como trabajo fuera del equipo.
- Inventar festivos si el calendario del año no está cargado.

## Pendiente

Lo que falta para confiar en registros reales está en [plan.md](plan.md). La cola de fichaje sin conexión ya no existe. Una corrección y una reunión ya no pueden pisar otro rato, dos correcciones seguidas no dejan una hora imposible, y el borrado de prueba solo existe en la demo.

Pablo, el 9 de octubre de 2026, dejó por confirmar quién valida y actualiza el calendario. La aplicación usa ya el calendario del municipio de Madrid para todas las personas. Esa confirmación sigue abierta en [reuniones.md](reuniones.md).
