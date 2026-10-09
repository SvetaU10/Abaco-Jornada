<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaJornada;
use App\Models\Aviso;
use App\Models\Correccion;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Services\JornadaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class JornadaController extends Controller
{
    public function __construct(private JornadaService $jornada) {}

    public function index(Request $request): View
    {
        $clave = 'festivo.'.$request->user()->id.'.'.now()->toDateString();

        return view('jornada.index', [
            ...$this->jornada->pantalla(
                $request->user(),
                $request->session()->get($clave),
            ),
            'puedeBorrarPrueba' => $this->puedeBorrarPrueba($request->user()->email),
        ]);
    }

    public function borrarPrueba(Request $request): RedirectResponse
    {
        if (! $this->puedeBorrarPrueba($request->user()->email)) {
            abort(403);
        }

        DB::transaction(function () use ($request) {
            $jornada = Jornada::query()
                ->where('user_id', $request->user()->id)
                ->whereDate('work_date', now()->toDateString())
                ->first();

            if ($jornada) {
                $tramos = Tramo::query()->where('jornada_id', $jornada->id)->pluck('id');
                Correccion::query()->whereIn('tramo_id', $tramos)->delete();
                Tramo::query()->where('jornada_id', $jornada->id)->delete();
                $jornada->delete();
            }

            Aviso::query()
                ->where('user_id', $request->user()->id)
                ->whereDate('work_date', now()->toDateString())
                ->delete();
        });

        $request->session()->forget('festivo.'.$request->user()->id.'.'.now()->toDateString());

        return back();
    }

    private function puedeBorrarPrueba(string $email): bool
    {
        if (! app()->environment('local', 'testing')) {
            return false;
        }

        return in_array($email, [
            'ana.lopez@abaco.test',
            'marta.ruiz@abaco.test',
        ], true);
    }

    public function entrar(Request $request): RedirectResponse
    {
        $this->normalizarHora($request);
        if (! $request->filled('hora')) {
            return $this->hacer(fn () => $this->jornada->entrar($request->user()));
        }

        $datos = $request->validate([
            'hora' => ['required', 'date_format:H:i'],
        ], [
            'hora.required' => 'Elige una hora.',
            'hora.date_format' => 'Elige una hora.',
        ]);

        if ($datos['hora'] === now()->format('H:i')) {
            return $this->hacer(fn () => $this->jornada->entrar($request->user()));
        }

        return $this->hacer(fn () => $this->jornada->yaEstaba($request->user(), $datos['hora']));
    }

    public function salir(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->salir($request->user()));
    }

    public function pausar(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->pausar($request->user()));
    }

    public function volver(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->volver($request->user()));
    }

    public function yaEstaba(Request $request): RedirectResponse
    {
        $this->normalizarHora($request);
        $datos = $request->validate([
            'hora' => ['required', 'date_format:H:i'],
        ], [
            'hora.required' => 'Elige una hora.',
            'hora.date_format' => 'Elige una hora.',
        ]);

        return $this->hacer(fn () => $this->jornada->yaEstaba($request->user(), $datos['hora']));
    }

    public function completar(Request $request): RedirectResponse
    {
        $this->normalizarHora($request);
        $datos = $request->validate([
            'hora' => ['required', 'date_format:H:i'],
        ], [
            'hora.required' => 'Elige una hora.',
            'hora.date_format' => 'Elige una hora.',
        ]);

        return $this->hacer(fn () => $this->jornada->completarOlvido($request->user(), $datos['hora']));
    }

    public function reunion(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->reunion($request->user()));
    }

    public function cerrar(Request $request): RedirectResponse
    {
        $this->normalizarHora($request);
        $datos = $request->validate([
            'hora' => ['required', 'date_format:H:i'],
        ], [
            'hora.required' => 'Elige una hora.',
            'hora.date_format' => 'Elige una hora.',
        ]);

        return $this->hacer(fn () => $this->jornada->cerrarALas($request->user(), $datos['hora']));
    }

    public function seguir(Request $request): RedirectResponse
    {
        return $this->hacer(fn () => $this->jornada->seguir($request->user()));
    }

    public function fuera(Request $request): RedirectResponse
    {
        $this->normalizarHora($request, 'inicio');
        $this->normalizarHora($request, 'fin');
        $datos = $request->validate([
            'inicio' => ['required', 'date_format:H:i'],
            'fin' => ['required', 'date_format:H:i'],
        ], [
            'inicio.required' => 'Elige la hora de inicio.',
            'fin.required' => 'Elige la hora de fin.',
            'inicio.date_format' => 'Elige una hora.',
            'fin.date_format' => 'Elige una hora.',
        ]);

        return $this->hacer(fn () => $this->jornada->fuera($request->user(), $datos['inicio'], $datos['fin']));
    }

    public function crearIncidencia(): View
    {
        return view('jornada.incidencia');
    }

    public function incidencia(Request $request): RedirectResponse
    {
        $this->normalizarHora($request, 'inicio');
        $this->normalizarHora($request, 'fin');
        $datos = $request->validate([
            'inicio' => ['required', 'date_format:H:i'],
            'fin' => ['required', 'date_format:H:i'],
            'motivo' => ['required', 'string', 'max:500'],
        ], [
            'inicio.required' => 'Elige la hora de inicio.',
            'fin.required' => 'Elige la hora de fin.',
            'inicio.date_format' => 'Elige una hora.',
            'fin.date_format' => 'Elige una hora.',
            'motivo.required' => 'Escribe el motivo de la incidencia.',
        ]);

        return $this->hacer(fn () => $this->jornada->declararIncidencia(
            $request->user(),
            $datos['inicio'],
            $datos['fin'],
            $datos['motivo'],
        ));
    }

    public function festivo(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'respuesta' => ['required', 'in:si,no'],
        ]);

        $request->session()->put(
            'festivo.'.$request->user()->id.'.'.now()->toDateString(),
            $datos['respuesta'],
        );

        return back()->with('ok', $datos['respuesta'] === 'si'
            ? 'Guardado. Puedes registrar el trabajo de hoy.'
            : 'Guardado. Hoy no hay trabajo registrado.');
    }

    private function hacer(callable $accion): RedirectResponse
    {
        try {
            return back()->with('ok', $accion());
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage());
        }
    }

    private function normalizarHora(Request $request, string $campo = 'hora'): void
    {
        $hora = $request->input($campo);
        if (is_string($hora) && strlen($hora) >= 5) {
            $request->merge([$campo => substr($hora, 0, 5)]);
        }
    }
}
