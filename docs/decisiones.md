# Decisiones

Cada fila enlaza la regla o la tarea que la aplica. El estado «vigente» es lo que el código hace hoy. «Pendiente de aplicar» es un acuerdo de Pablo del 9 de octubre de 2026 que el código todavía no cumple.

## 2026-10-09 · Fichaje con conexión

- Decisión: el proyecto es una aplicación web. Cada fichaje requiere conexión y la confirmación del servidor.
- Motivo: la cola del navegador puede guardar el contenido de un formulario, reenviarlo con otra sesión y perder pendientes.
- Alternativa descartada: guardar la acción en el navegador y enviarla sola al recuperar la red.
- Estado: vigente. `public/js/reloj.js` ya no guarda ni reenvía formularios.
- Aplica en: [reglas-jornada.md](reglas-jornada.md). Prueba `test_la_incidencia_de_conexion_declara_horas_sin_fichar_en_directo`.

## 2026-10-09 · Una caída de internet es una incidencia

- Decisión: mostrar «No guardado: sin conexión». Al volver, la persona puede comunicar la incidencia y declarar las horas. Se conservan motivo, autor y momento de recepción. No es un fichaje en directo ni un olvido automático.
- Motivo: la falta de conexión suele ser ajena a la persona, y no debe ocultarse ni contarse como si hubiera fichado a la hora.
- Alternativa descartada: reenviar el formulario tal cual, o abrir un aviso de olvido solo porque no hubo red.
- Estado: vigente. El texto es «No guardado: sin conexión». Al volver la red, «Comunicar incidencia» guarda las horas declaradas, el motivo, la persona y la hora de recepción. No abre un fichaje en directo ni un aviso de olvido.
- Aplica en: [reglas-jornada.md](reglas-jornada.md). Prueba `test_la_incidencia_de_conexion_declara_horas_sin_fichar_en_directo`.

## 2026-10-07 · El calendario es el del centro de Madrid

- Decisión: todas las personas, también quien teletrabaja desde otro municipio, siguen los festivos del centro de Madrid.
- Motivo: el centro de trabajo está en el municipio de Madrid. No se aplica el calendario de la comunidad a cada centro, ni el del municipio de la ficha.
- Alternativa descartada: un calendario por municipio de residencia.
- Estado: vigente en `JornadaService::festivoDe`. Pablo dejó pendiente confirmar el centro y quién valida y actualiza el calendario. Ver [reuniones.md](reuniones.md).
- Aplica en: prueba `test_quien_esta_en_otra_comunidad_sigue_el_calendario_de_madrid`.

## 2026-10-07 · No se borra una hora al corregirla

- Decisión: la corrección añade la hora nueva y conserva la anterior, quién la cambió y cuándo. Un informe ya emitido no se reescribe solo.
- Motivo: el registro tiene que poder contrastarse. La retención de contraste es el artículo 34.9 del Estatuto de los Trabajadores, cuatro años. Ábaco lo confirma.
- Alternativa descartada: sustituir la hora y olvidar la anterior.
- Estado: vigente en `correcciones`. La salida guarda además quién la declaró y cuándo, en `cerrado_por` y `cerrado_at`. Una corrección posterior no borra esa declaración.
- Aplica en: prueba `test_cambiar_una_hora_conserva_la_anterior`.

## 2026-10-07 · La baja no borra la jornada

- Decisión: desactivar a una persona pone `active` en falso y no borra sus jornadas. Las claves foráneas usan `restrictOnDelete`.
- Motivo: el historial no puede desaparecer al cerrar el acceso.
- Alternativa descartada: borrar en cascada los fichajes de esa persona.
- Estado: vigente.
- Aplica en: prueba `test_la_baja_conserva_la_jornada`.

## 2026-10-07 · La dirección no ficha

- Decisión: el papel `jefe` consulta. No entra en las rutas que escriben fichajes, horarios o accesos. Abrir el registro de otra persona exige un motivo y deja constancia.
- Motivo: la alta dirección queda fuera del registro de jornada y la consulta tiene que ser trazable.
- Alternativa descartada: dar a la dirección las mismas acciones que a la responsable.
- Estado: vigente.
- Aplica en: `tests/Feature/JefeTest.php`.

## 2026-10-07 · El exceso no se autoriza en la aplicación

- Decisión: salir a las 18:35 guarda las 18:35. No se pide autorización ni se etiqueta como hora extra.
- Motivo: Ábaco ve el total y decide. La aplicación no convierte el exceso en una categoría laboral.
- Alternativa descartada: un módulo de horas extra con aprobación.
- Estado: vigente en el cálculo. La frase «No es una hora extra» está pendiente de revisión, porque mostrar el exceso no clasifica el tiempo.
- Aplica en: prueba `test_seguir_guarda_la_salida_real_sin_pedir_autorizacion`.
