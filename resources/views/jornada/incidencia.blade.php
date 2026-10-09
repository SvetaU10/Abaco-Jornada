@extends('layouts.app', ['titulo' => 'Incidencia'])

@section('contenido')
    <a class="volver" href="{{ route('jornada') }}">Volver a hoy</a>

    <section class="panel pantalla-formulario">
        <p class="etiqueta-estado">Sin conexión</p>
        <h1>Declarar las horas</h1>
        <p class="secundario pantalla-texto">El fichaje no se guardó. Cuéntanos qué pasó y escribe el inicio y el fin. No es un fichaje en directo ni un olvido: el servidor anota quién lo declara y a qué hora lo recibe.</p>

        <form method="post" action="{{ route('jornada.incidencia') }}" class="formulario">
            @csrf
            <label for="incidencia-motivo">Qué pasó</label>
            <textarea id="incidencia-motivo" name="motivo" maxlength="500" required>{{ old('motivo') }}</textarea>

            <div class="horas-par">
                <div>
                    <label for="incidencia-inicio">Inicio</label>
                    <x-hora id="incidencia-inicio" name="inicio" :valor="old('inicio')" :max="now()->format('H:i')" />
                </div>
                <div>
                    <label for="incidencia-fin">Fin</label>
                    <x-hora id="incidencia-fin" name="fin" :valor="old('fin')" :max="now()->format('H:i')" />
                </div>
            </div>

            <button class="boton boton-principal" type="submit">Guardar incidencia</button>
        </form>
    </section>
@endsection
