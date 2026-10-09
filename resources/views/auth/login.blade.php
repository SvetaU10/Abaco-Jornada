@extends('layouts.app', ['acceso' => true, 'titulo' => 'Entrar'])

@section('contenido')
    <img class="isotipo isotipo-acceso" src="{{ asset('marca/logos/abacoqd_isotipo.svg') }}" alt="">
    <h1>Ábaco Jornada</h1>
    <p class="secundario">Entra con el correo que te dio tu responsable.</p>

    <form method="post" action="{{ route('login') }}" class="formulario">
        @csrf
        <label for="email">Correo</label>
        <input id="email" name="email" type="email" autocomplete="username" value="{{ old('email') }}" required>

        <label for="password">Contraseña</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>

        <button class="boton boton-principal" type="submit">Entrar</button>
    </form>

    @if (app()->environment('local', 'testing'))
        <details class="pruebas">
            <summary>Cuentas de prueba</summary>
            <p>Ana López · ana.lopez@abaco.test</p>
            <p>Marta Ruiz, responsable · marta.ruiz@abaco.test</p>
            <p>Carmen Ortega, dirección · carmen.ortega@abaco.test</p>
            <p>Contraseña: Jornada2026</p>
        </details>
    @endif
@endsection
