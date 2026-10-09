<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $titulo ?? 'Jornada' }} · Ábaco</title>
    <link rel="icon" href="{{ asset('marca/logos/abacoqd_isotipo.svg') }}" type="image/svg+xml">
    <link rel="stylesheet" href="{{ asset('css/paleta.css') }}?v={{ filemtime(public_path('css/paleta.css')) }}">
    <link rel="stylesheet" href="{{ asset('css/jornada.css') }}?v={{ filemtime(public_path('css/jornada.css')) }}">
</head>
<body @if ($pendiente ?? false) data-pendiente="1" @endif>
    <a class="saltar" href="#contenido">Saltar al contenido</a>

    @auth
        <header class="barra">
            <a class="marca" href="{{ auth()->user()->esJefe() ? route('jefe.index') : route('jornada') }}">
                <span class="marca-logo">
                    <img class="isotipo" src="{{ asset('marca/logos/abacoqd_isotipo.svg') }}" alt="">
                </span>
                <span class="marca-nombre">Jornada</span>
            </a>
            <nav class="menu" aria-label="Secciones">
                @if (auth()->user()->esJefe())
                    <a href="{{ route('jefe.index') }}" @if (request()->routeIs('jefe.index', 'jefe.persona', 'jefe.registro', 'jefe.motivo', 'jefe.dia')) aria-current="page" @endif>Equipo</a>
                    <a href="{{ route('jefe.consultas') }}" @if (request()->routeIs('jefe.consultas')) aria-current="page" @endif>Consultas</a>
                @else
                    <a href="{{ route('jornada') }}" @if (request()->routeIs('jornada')) aria-current="page" @endif>Hoy</a>
                    <a href="{{ route('registro') }}" @if (request()->routeIs('registro', 'registro.show')) aria-current="page" @endif>Mi registro</a>
                    <a href="{{ route('calendario') }}" @if (request()->routeIs('calendario')) aria-current="page" @endif>Calendario</a>
                    @if (auth()->user()->esResponsable())
                        <a href="{{ route('equipo.index') }}" @if (request()->routeIs('equipo.*')) aria-current="page" @endif>Equipo</a>
                    @endif
                @endif
            </nav>
            <div class="barra-cuenta">
                @if (auth()->user()->esJefe())
                    <span class="solo-lectura">Solo lectura</span>
                @endif
                <span class="persona">{{ auth()->user()->name }}</span>
                <form method="post" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit">Salir</button>
                </form>
            </div>
        </header>
    @endauth

    <div class="pagina {{ ($acceso ?? false) ? 'pagina-acceso' : '' }} {{ ($compacta ?? false) ? 'pagina-fichar' : '' }}">
        <p class="sin-conexion" data-sin-conexion hidden>No guardado: sin conexión.</p>

        @unless ($compacta ?? false)
            @if (session('ok'))
                <p class="guardado boton" role="status">{{ session('ok') }}</p>
            @endif
            @if (session('aviso'))
                <p class="franja" role="status">{{ session('aviso') }}</p>
            @endif
            @if ($errors->any())
                <p class="franja" role="alert">{{ $errors->first() }}</p>
            @endif
        @endunless

        <main id="contenido">
            @yield('contenido')
        </main>

        @if (auth()->check() && auth()->user()->esJefe())
            <p class="nota-datos">Aquí solo consultas. Lo guardado no se cambia, y cada vez que abres el registro de una persona queda escrito quién lo hizo y por qué.</p>
        @else
            <p class="nota-datos">Guardamos tu nombre, tu horario y las horas de la jornada. No guardamos ubicación.</p>
        @endif
    </div>
    <script src="{{ asset('js/reloj.js') }}?v={{ filemtime(public_path('js/reloj.js')) }}"></script>
</body>
</html>
