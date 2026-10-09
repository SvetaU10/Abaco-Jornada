<?php

namespace App\Http\Controllers;

use App\Exceptions\ReglaJornada;
use App\Models\Consulta;
use App\Models\Correccion;
use App\Models\Explicacion;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Services\JornadaService;
use App\Services\RegistroCsv;
use App\Support\Tiempo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegistroController extends Controller
{
    public function __construct(private JornadaService $jornada, private RegistroCsv $csv) {}

    public function index(Request $request): View
    {
        return $this->mostrar($request, $request->user(), $request->user(), false);
    }

    public function de(Request $request, User $user): View
    {
        if ($motivo = $this->pedirMotivo($request, $user)) {
            return $motivo;
        }

        return $this->mostrar($request, $request->user(), $user, true);
    }

    public function motivo(Request $request, User $user): RedirectResponse
    {
        $this->autorizar($request->user(), $user);

        $datos = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ], [
            'reason.required' => 'Escribe el motivo de la consulta.',
            'reason.max' => 'El motivo puede tener hasta 500 caracteres.',
        ]);

        Consulta::create([
            'viewer_id' => $request->user()->id,
            'subject_id' => $user->id,
            'reason' => trim($datos['reason']),
        ]);

        $request->session()->put('consulta.'.$user->id, true);

        return redirect()->intended(route('equipo.registro', $user))
            ->with('ok', 'Guardado. Esta consulta queda escrita.');
    }

    public function show(Request $request, Jornada $jornada): View
    {
        $jornada->load('user', 'tramos.correcciones.autor', 'tramos.cerradoPor');
        $this->autorizar($request->user(), $jornada->user);

        if ($motivo = $this->pedirMotivo($request, $jornada->user)) {
            return $motivo;
        }

        return view('registro.show', [
            'jornada' => $jornada,
            'persona' => $jornada->user,
            'total' => Tiempo::texto($this->jornada->minutosRegistrados($jornada->user, $jornada)),
            'porEncima' => $this->jornada->porEncimaRegistrado($jornada->user, $jornada),
            'puedeCorregir' => $request->user()->esResponsable()
                || ($request->user()->id === $jornada->user_id && $jornada->work_date->gte(now()->subDays(7)->startOfDay())),
            'motivos' => Correccion::MOTIVOS,
            'esResponsable' => $request->user()->esResponsable(),
        ]);
    }

    public function corregir(Request $request, Tramo $tramo): RedirectResponse
    {
        $hora = $request->input('hora');
        if (is_string($hora) && strlen($hora) >= 5) {
            $request->merge(['hora' => substr($hora, 0, 5)]);
        }

        $datos = $request->validate([
            'campo' => ['required', 'in:started_at,ended_at'],
            'hora' => ['required', 'date_format:H:i'],
            'motivo' => ['required', 'in:'.implode(',', array_keys(Correccion::MOTIVOS))],
            'nota' => ['nullable', 'string', 'max:500'],
        ], [
            'hora.required' => 'Elige una hora.',
            'hora.date_format' => 'Elige una hora.',
            'motivo.required' => 'Elige un motivo.',
            'nota.max' => 'La nota puede tener hasta 500 caracteres.',
        ]);

        try {
            $mensaje = $this->jornada->corregir(
                $request->user(),
                $tramo,
                $datos['campo'],
                $datos['hora'],
                $datos['motivo'],
                $datos['nota'] ?? null,
            );
        } catch (ReglaJornada $e) {
            return back()->with('aviso', $e->getMessage());
        }

        return back()->with('ok', $mensaje);
    }

    public function explicar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'body' => ['required', 'string', 'max:500'],
            'work_date' => ['required', 'date'],
        ], [
            'body.required' => 'Escribe la explicación.',
            'body.max' => 'La nota puede tener hasta 500 caracteres.',
            'work_date.required' => 'Elige el día.',
        ]);

        Explicacion::create([
            'user_id' => $request->user()->id,
            'body' => trim($datos['body']),
            'work_date' => $datos['work_date'],
        ]);

        $this->jornada->marcarExplicados($request->user(), $datos['work_date']);

        return back()->with('ok', 'Guardado. Tu explicación queda en el registro.');
    }

    public function csv(Request $request): StreamedResponse
    {
        return $this->descargar($request->user(), $request->user());
    }

    public function csvDe(Request $request, User $user): StreamedResponse|View
    {
        if ($motivo = $this->pedirMotivo($request, $user)) {
            return $motivo;
        }

        return $this->descargar($request->user(), $user);
    }

    private function mostrar(Request $request, User $visor, User $persona, bool $consulta): View
    {
        $this->autorizar($visor, $persona);

        $desde = $request->query('desde');
        $hasta = $request->query('hasta');
        $jornadas = Jornada::query()
            ->with('tramos')
            ->where('user_id', $persona->id)
            ->when(is_string($desde) && $desde !== '', fn ($q) => $q->whereDate('work_date', '>=', $desde))
            ->when(is_string($hasta) && $hasta !== '', fn ($q) => $q->whereDate('work_date', '<=', $hasta))
            ->orderByDesc('work_date')
            ->paginate(60)
            ->withQueryString();

        return view('registro.index', [
            'persona' => $persona,
            'consulta' => $consulta,
            'jornadas' => $jornadas,
            'desde' => is_string($desde) ? $desde : '',
            'hasta' => is_string($hasta) ? $hasta : '',
            'servicio' => $this->jornada,
            'explicaciones' => $persona->explicaciones()->latest()->limit(10)->get(),
            'consultas' => $consulta
                ? Consulta::query()->with('viewer')->where('subject_id', $persona->id)->latest()->limit(10)->get()
                : collect(),
            'esResponsable' => $visor->esResponsable(),
            'propio' => $visor->id === $persona->id,
        ]);
    }

    private function descargar(User $visor, User $persona): StreamedResponse
    {
        $this->autorizar($visor, $persona);

        return $this->csv->descargar($persona);
    }

    private function pedirMotivo(Request $request, User $persona): ?View
    {
        if ($request->user()->id === $persona->id || $request->session()->get('consulta.'.$persona->id)) {
            return null;
        }

        $request->session()->put('url.intended', url()->full());

        return view('registro.motivo', [
            'persona' => $persona,
            'esResponsable' => true,
        ]);
    }

    private function autorizar(User $visor, User $persona): void
    {
        if ($visor->id !== $persona->id && ! $visor->esResponsable()) {
            abort(403);
        }
    }
}
