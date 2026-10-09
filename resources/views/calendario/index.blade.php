@extends('layouts.app', ['ancha' => true, 'titulo' => 'Calendario'])

@section('contenido')
    <h1>Calendario {{ $anio }}</h1>
    @if ($sinCalendario)
        <p class="franja" role="status">El calendario de {{ $anio }} no está cargado. No inventamos festivos. Los días ya trabajados se quedan como se registraron.</p>
    @else
        <p class="secundario">El centro de trabajo está en Madrid. Este calendario vale para toda la plantilla, también para quien esté en otra comunidad.</p>
    @endif

    <ul class="lista">
        @forelse ($festivos as $festivo)
            <li>
                <span>{{ \App\Support\Tiempo::fecha($festivo->holiday_date) }} · {{ $festivo->name }}</span>
                <strong>{{ $festivo->ambito() }}</strong>
            </li>
        @empty
            <li>Este año no tiene festivos cargados.</li>
        @endforelse
    </ul>

    @unless ($esResponsable)
        <p class="secundario">Puedes consultar los festivos. Los carga tu responsable.</p>
    @endunless

    @if ($esResponsable)

    <form method="post" action="{{ route('calendario.store') }}" class="formulario">
        @csrf
        <label for="holiday_date">Día</label>
        <input id="holiday_date" name="holiday_date" type="date" required>
        <label for="name">Nombre</label>
        <input id="name" name="name" required maxlength="120">
        <button class="boton boton-principal" type="submit">Guardar festivo</button>
    </form>

    <h2>Fallo común</h2>
    <p class="secundario">Si el mismo aviso sale a la vez por una caída o un calendario mal cargado, no entra en la cuenta de cada persona.</p>
    <form method="post" action="{{ route('calendario.fallo') }}" class="formulario">
        @csrf
        <label for="falla_date">Día</label>
        <input id="falla_date" name="falla_date" type="date" required>
        <label for="note">Qué falló</label>
        <input id="note" name="note" required maxlength="200">
        <button class="boton boton-secundario" type="submit">Marcar fallo común</button>
    </form>
    @if ($fallos->isNotEmpty())
        <ul class="lista">
            @foreach ($fallos as $fallo)
                <li>
                    <span>{{ \App\Support\Tiempo::fecha($fallo->falla_date) }}</span>
                    <span class="secundario">{{ $fallo->note }}</span>
                </li>
            @endforeach
        </ul>
    @endif
    @endif
@endsection
