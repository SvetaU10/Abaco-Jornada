@extends('layouts.app', ['ancha' => true, 'titulo' => 'Día'])

@section('contenido')
    <a class="volver" href="{{ $esResponsable && auth()->id() !== $persona->id ? route('equipo.registro', $persona) : route('registro') }}">Volver al registro</a>
    <h1>{{ \App\Support\Tiempo::fecha($jornada->work_date) }}</h1>
    <p>{{ $persona->name }}</p>
    <p>Total {{ $total }}@if ($jornada->closed_late). Cerrada al día siguiente.@endif</p>
    @if ($porEncima)
        <p class="secundario">Tiempo por encima del horario: {{ \App\Support\Tiempo::texto($porEncima) }}. No es una hora extra y no hace falta autorización.</p>
    @endif
    <p class="secundario">Si una hora cambia, se añade la nueva. La anterior se conserva, con quién la cambió y cuándo.</p>

    @foreach ($jornada->tramos as $tramo)
        <article class="tarjeta">
            <h2>{{ $tramo->tipo === 'pausa' ? 'Pausa' : 'Trabajo' }}</h2>
            <p>Desde las {{ \App\Support\Tiempo::hora($tramo->started_at) }}
                @if ($tramo->ended_at)
                    hasta las {{ \App\Support\Tiempo::hora($tramo->ended_at) }}
                @else
                    · todavía abierto
                @endif
                @if ($tramo->fuera_del_equipo)
                    · fuera del equipo
                @endif
                @if ($tramo->situacion === 'incidencia')
                    · incidencia de conexión, declarada por {{ $persona->name }} y recibida a las {{ \App\Support\Tiempo::hora($tramo->anotado_at) }}
                    @if ($tramo->nota)
                        · {{ $tramo->nota }}
                    @endif
                @elseif ($tramo->anotado_at && $tramo->anotado_at->format('H:i') !== $tramo->started_at->format('H:i'))
                    · anotado a las {{ \App\Support\Tiempo::hora($tramo->anotado_at) }}
                @endif
            </p>
            @if ($tramo->cerrado_at)
                <p class="secundario">Salida declarada por {{ $tramo->cerradoPor?->name }} el {{ \App\Support\Tiempo::fecha($tramo->cerrado_at) }} a las {{ \App\Support\Tiempo::hora($tramo->cerrado_at) }}.</p>
            @endif

            @if ($puedeCorregir)
                <form method="post" action="{{ route('tramos.corregir', $tramo) }}" class="formulario">
                    @csrf
                    <label for="campo-{{ $tramo->id }}">Qué hora cambias</label>
                    <select id="campo-{{ $tramo->id }}" name="campo" required>
                        <option value="started_at">Inicio</option>
                        @if ($tramo->ended_at)
                            <option value="ended_at">Fin</option>
                        @endif
                    </select>
                    <label for="hora-{{ $tramo->id }}">Hora nueva</label>
                    <x-hora id="hora-{{ $tramo->id }}" name="hora" />
                    <label for="motivo-{{ $tramo->id }}">Motivo</label>
                    <select id="motivo-{{ $tramo->id }}" name="motivo" required>
                        @foreach ($motivos as $clave => $texto)
                            <option value="{{ $clave }}">{{ $texto }}</option>
                        @endforeach
                    </select>
                    <label for="nota-{{ $tramo->id }}">Nota, si hace falta</label>
                    <textarea id="nota-{{ $tramo->id }}" name="nota" maxlength="500"></textarea>
                    <p class="secundario">No pongas datos de salud ni de clientes.</p>
                    <button class="boton boton-secundario" type="submit">Guardar corrección</button>
                </form>
            @else
                <p class="secundario">Para cambiar este día, habla con tu responsable.</p>
            @endif

            @foreach ($tramo->correcciones as $correccion)
                <p class="secundario">
                    Antes {{ \App\Support\Tiempo::hora($correccion->previous_value) }},
                    ahora {{ \App\Support\Tiempo::hora($correccion->new_value) }}.
                    {{ $correccion->motivoTexto() }}.
                    {{ $correccion->autor->name }},
                    {{ \App\Support\Tiempo::fecha($correccion->created_at) }}
                    {{ $correccion->created_at->format('H:i') }}.
                    @if ($correccion->note)
                        {{ $correccion->note }}
                    @endif
                </p>
            @endforeach
        </article>
    @endforeach
@endsection
