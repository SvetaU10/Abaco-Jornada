@extends('layouts.app', ['ancha' => true, 'titulo' => 'Registro'])

@section('contenido')
    <h1>{{ $consulta ? 'Registro de '.$persona->name : 'Mi registro' }}</h1>
    <p class="secundario">Ves los tuyos y puedes sacar una copia. Se conservan cuatro años, el plazo del artículo 34.9 del Estatuto de los Trabajadores. Ábaco lo confirma. Una copia ya sacada no se reescribe sola.</p>
    <p>
        <a class="boton boton-secundario" href="{{ $consulta ? route('equipo.csv', $persona) : route('registro.csv') }}">Descargar copia</a>
    </p>
    <form method="get" class="filtro-registro">
        <label for="desde">Desde</label>
        <input id="desde" name="desde" type="date" value="{{ $desde }}">
        <label for="hasta">Hasta</label>
        <input id="hasta" name="hasta" type="date" value="{{ $hasta }}">
        <button class="boton boton-secundario" type="submit">Ver</button>
    </form>

    @if ($jornadas->isEmpty())
        <p>Todavía no hay jornadas.</p>
    @else
        <ul class="lista">
            @foreach ($jornadas as $jornada)
                <li>
                    <a href="{{ route('registro.show', $jornada) }}">
                        <span>{{ \App\Support\Tiempo::fecha($jornada->work_date) }}</span>
                        <strong>{{ \App\Support\Tiempo::texto($servicio->minutosRegistrados($persona, $jornada)) }}</strong>
                    </a>
                    <span class="secundario">
                        @if ($jornada->tramoAbierto())
                            Pendiente de cerrar
                        @elseif ($jornada->closed_late)
                            Cerrada al día siguiente
                        @else
                            Cerrada
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
        @if ($jornadas->hasPages())
            <p class="paginas">
                @if ($jornadas->previousPageUrl())
                    <a href="{{ $jornadas->previousPageUrl() }}">Días más recientes</a>
                @endif
                <span>Página {{ $jornadas->currentPage() }} de {{ $jornadas->lastPage() }}</span>
                @if ($jornadas->nextPageUrl())
                    <a href="{{ $jornadas->nextPageUrl() }}">Días anteriores</a>
                @endif
            </p>
        @endif
    @endif

    @if ($propio)
        <form method="post" action="{{ route('registro.explicar') }}" class="formulario">
            @csrf
            <label for="work_date">Día</label>
            <input id="work_date" name="work_date" type="date" required value="{{ old('work_date', now()->toDateString()) }}">
            <label for="body">Explicar un cierre</label>
            <textarea id="body" name="body" maxlength="500" required></textarea>
            <p class="secundario">No pongas datos de salud ni de clientes.</p>
            <button class="boton boton-secundario" type="submit">Guardar explicación</button>
        </form>
    @endif

    @if ($explicaciones->isNotEmpty())
        <h2>Explicaciones</h2>
        <ul class="lista">
            @foreach ($explicaciones as $explicacion)
                <li>
                    <span>{{ $explicacion->body }}</span>
                    <span class="secundario">{{ \App\Support\Tiempo::fecha($explicacion->created_at) }}@if ($explicacion->work_date) · {{ \App\Support\Tiempo::fecha($explicacion->work_date) }}@endif</span>
                </li>
            @endforeach
        </ul>
    @endif

    @if ($consulta && $consultas->isNotEmpty())
        <h2>Consultas</h2>
        <ul class="lista">
            @foreach ($consultas as $visita)
                <li>
                    <span>{{ $visita->viewer->name }}@if ($visita->reason). {{ $visita->reason }}@endif</span>
                    <span class="secundario">{{ \App\Support\Tiempo::fecha($visita->created_at) }} {{ $visita->created_at->format('H:i') }}</span>
                </li>
            @endforeach
        </ul>
    @endif
@endsection
