<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaJornada;
use App\Models\Ausencia;
use App\Models\Consulta;
use App\Models\Horario;
use App\Models\User;
use App\Services\JornadaService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class EquipoController extends Controller
{
    public function __construct(private JornadaService $jornada) {}

    public function index(): View
    {
        return view('equipo.index', [
            'personas' => User::query()->where('role', '!=', 'jefe')->orderBy('name')->get(),
            'esResponsable' => true,
        ]);
    }

    public function create(): View
    {
        return view('equipo.create', ['esResponsable' => true]);
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $this->validarPersona($request, true);

        try {
            DB::transaction(function () use ($datos) {
                $persona = User::create([
                    'name' => $datos['name'],
                    'email' => $datos['email'],
                    'password' => $datos['password'],
                    'role' => 'trabajadora',
                    'active' => true,
                    'starts_on' => $datos['starts_on'],
                    'municipality' => $datos['municipality'],
                ]);
                $this->guardarHorario($persona, $datos, $datos['starts_on']);
            });
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage())->withInput();
        }

        return redirect()->route('equipo.index')->with('ok', 'Guardado. '.$datos['name'].' ya puede entrar.');
    }

    public function show(User $user): View
    {
        return view('equipo.show', [
            'persona' => $user,
            'horarios' => $user->horarios()->orderByDesc('effective_from')->get(),
            'ausencias' => $user->ausencias()->orderByDesc('absence_date')->get(),
            'consultas' => Consulta::query()->with('viewer')->where('subject_id', $user->id)->latest()->limit(8)->get(),
            'esResponsable' => true,
        ]);
    }

    public function horario(Request $request, User $user): RedirectResponse
    {
        $datos = $this->validarHorario($request);
        $datos['effective_from'] = $request->validate([
            'effective_from' => ['required', 'date', 'after_or_equal:today'],
        ], [
            'effective_from.required' => 'Elige desde qué día vale el horario.',
            'effective_from.after_or_equal' => 'El horario nuevo vale desde hoy o un día posterior. Los días anteriores se conservan.',
        ])['effective_from'];

        try {
            $this->guardarHorario($user, $datos, $datos['effective_from']);
            $this->jornada->excluirAvisosDeHorario($user, $datos['effective_from']);
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage())->withInput();
        }

        return back()->with('ok', 'Guardado. El horario vale desde el '.$datos['effective_from'].'. Los avisos desde ese día no cuentan. Los anteriores se conservan.');
    }

    public function ausencia(Request $request, User $user): RedirectResponse
    {
        $datos = $request->validate([
            'absence_date' => ['required', 'date'],
            'tipo' => ['required', 'in:vacaciones,permiso'],
        ], [
            'absence_date.required' => 'Elige el día.',
            'tipo.required' => 'Elige vacaciones o permiso.',
        ]);

        try {
            Ausencia::create([
                'user_id' => $user->id,
                'absence_date' => $datos['absence_date'],
                'tipo' => $datos['tipo'],
            ]);
        } catch (UniqueConstraintViolationException) {
            return back()->with('aviso', 'Ese día ya tiene una ausencia.');
        }

        return back()->with('ok', 'Guardado. Ese día no se espera fichaje.');
    }

    public function baja(Request $request, User $user): RedirectResponse
    {
        if ($request->user()->id === $user->id) {
            return back()->with('aviso', 'No puedes cerrar tu propio acceso.');
        }

        $user->update(['active' => false]);

        return back()->with('ok', 'Acceso cerrado. Los registros de '.$user->name.' se conservan.');
    }

    private function validarPersona(Request $request, bool $conClave): array
    {
        $reglas = [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190', 'unique:users,email'],
            'starts_on' => ['required', 'date'],
            'municipality' => ['required', 'string', 'max:120'],
        ];
        if ($conClave) {
            $reglas['password'] = ['required', 'string', 'min:8'];
        }

        $datos = $request->validate($reglas, [
            'name.required' => 'Escribe el nombre.',
            'email.required' => 'Escribe el correo.',
            'email.unique' => 'Ese correo ya está en uso.',
            'password.min' => 'La contraseña necesita al menos 8 caracteres.',
            'starts_on.required' => 'Elige desde qué día cuenta el registro.',
            'municipality.required' => 'Escribe el municipio del centro.',
        ]);

        return array_merge($datos, $this->validarHorario($request));
    }

    private function validarHorario(Request $request): array
    {
        foreach (['morning_start', 'morning_end', 'afternoon_start', 'afternoon_end'] as $campo) {
            $valor = $request->input($campo);
            if (is_string($valor) && strlen($valor) >= 5) {
                $request->merge([$campo => substr($valor, 0, 5)]);
            }
        }

        return $request->validate([
            'morning_start' => ['required', 'date_format:H:i'],
            'morning_end' => ['required', 'date_format:H:i'],
            'afternoon_start' => ['required', 'date_format:H:i'],
            'afternoon_end' => ['required', 'date_format:H:i'],
        ], [
            'morning_start.required' => 'Elige la hora de inicio.',
            'morning_end.required' => 'Elige el fin de la mañana.',
            'afternoon_start.required' => 'Elige el inicio de la tarde.',
            'afternoon_end.required' => 'Elige el fin de la jornada.',
            '*.date_format' => 'Elige una hora.',
        ]);
    }

    private function guardarHorario(User $persona, array $datos, string $desde): void
    {
        if (! ($datos['morning_start'] < $datos['morning_end']
            && $datos['morning_end'] <= $datos['afternoon_start']
            && $datos['afternoon_start'] < $datos['afternoon_end'])) {
            throw new ReglaJornada('Revisa el horario: la mañana va antes que la tarde, y cada tramo termina después de empezar.');
        }

        Horario::create([
            'user_id' => $persona->id,
            'morning_start' => $datos['morning_start'],
            'morning_end' => $datos['morning_end'],
            'afternoon_start' => $datos['afternoon_start'],
            'afternoon_end' => $datos['afternoon_end'],
            'effective_from' => $desde,
        ]);
    }
}
