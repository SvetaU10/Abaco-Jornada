@extends('layouts.app', ['titulo' => 'Día'])

@section('contenido')
    <p><a class="enlace" href="{{ route('jefe.registro', ['user' => $persona, 'mes' => $jornada->work_date->format('Y-m')]) }}">Volver al registro</a></p>
    <h1>{{ \App\Support\Tiempo::fecha($jornada->work_date) }}</h1>
    <p>{{ $persona->name }}</p>
    <p>Total {{ $total }}@if ($jornada->closed_late). Cerrada al día siguiente.@endif</p>
    @if ($porEncima)
        <p class="secundario">Tiempo por encima del horario: {{ \App\Support\Tiempo::texto($porEncima) }}. No es una hora extra y no hace falta autorización.</p>
    @endif
    <p class="secundario">Solo lectura. Si una hora cambia, se añade la nueva y la anterior se conserva, con quién la cambió y cuándo.</p>

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
