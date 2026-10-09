<?php

namespace App\Http\Controllers;

use App\Models\Consulta;
use App\Models\Jornada;
use App\Models\User;
use App\Services\JornadaService;
use App\Services\RegistroCsv;
use App\Support\Tiempo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Vista de la dirección. Solo consulta: ninguna acción de aquí cambia un
 * horario, una hora o un acceso. Lo único que se escribe es el motivo de
 * cada consulta, para que quede constancia de quién miró y por qué.
 */
class JefeController extends Controller
{
    private const MESES_QUE_SE_CONSERVAN = 48;

    public function __construct(private JornadaService $jornada, private RegistroCsv $csv) {}

    public function index(): View
    {
        $ahora = now();

        $personas = User::query()
            ->where('role', '!=', 'jefe')
            ->orderByDesc('active')
            ->orderBy('name')
            ->get()
            ->map(fn (User $persona) => [
                'persona' => $persona,
                'horario' => $persona->horarioEn($ahora),
                'estado' => $this->jornada->estadoDe($persona, $ahora),
            ]);

        return view('jefe.index', [
            'fecha' => Tiempo::fecha($ahora),
            'personas' => $personas,
            'cuentas' => [
                'trabajando' => $personas->where('estado.estado', 'trabajando')->count(),
                'pausa' => $personas->where('estado.estado', 'pausa')->count(),
                'pendiente' => $personas->where('estado.estado', 'pendiente')->count(),
            ],
        ]);
    }

    public function persona(User $user): View
    {
        return view('jefe.persona', [
            'persona' => $user,
            'estado' => $this->jornada->estadoDe($user),
            'horarios' => $user->horarios()->orderByDesc('effective_from')->get(),
            'ausencias' => $user->ausencias()->orderByDesc('absence_date')->get(),
            'consultas' => $this->consultasDe($user, 8),
        ]);
    }

    public function registro(Request $request, User $user): View
    {
        if ($motivo = $this->pedirMotivo($request, $user)) {
            return $motivo;
        }

        $ahora = now();
        $mes = $this->mesElegido($request, $user, $ahora);

        $jornadas = Jornada::query()
            ->with('tramos.correcciones')
            ->where('user_id', $user->id)
            ->whereDate('work_date', '>=', $mes->toDateString())
            ->whereDate('work_date', '<=', $mes->copy()->endOfMonth()->toDateString())
            ->orderByDesc('work_date')
            ->get();

        return view('jefe.registro', [
            'persona' => $user,
            'mes' => $mes->format('Y-m'),
            'meses' => $this->mesesDisponibles($user, $ahora),
            'mesTexto' => $mes->copy()->locale('es')->isoFormat('MMMM [de] YYYY'),
            'resumen' => $this->jornada->resumenDelMes($user, $mes, $jornadas, $ahora),
            'semanas' => $this->porSemanas($user, $jornadas, $ahora),
            'servicio' => $this->jornada,
            'explicaciones' => $user->explicaciones()->latest()->limit(10)->get(),
            'consultas' => $this->consultasDe($user, 10),
        ]);
    }

    public function dia(Request $request, Jornada $jornada): View
    {
        $jornada->load('user', 'tramos.correcciones.autor', 'tramos.cerradoPor');
        $persona = $jornada->user;

        if ($motivo = $this->pedirMotivo($request, $persona)) {
            return $motivo;
        }

        return view('jefe.dia', [
            'jornada' => $jornada,
            'persona' => $persona,
            'total' => Tiempo::texto($this->jornada->minutosRegistrados($persona, $jornada)),
            'porEncima' => $this->jornada->porEncimaRegistrado($persona, $jornada),
        ]);
    }

    public function motivo(Request $request, User $user): RedirectResponse
    {
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

        return redirect()->intended(route('jefe.registro', $user))
            ->with('ok', 'Guardado. Esta consulta queda escrita.');
    }

    public function csv(Request $request, User $user): StreamedResponse|View
    {
        if ($motivo = $this->pedirMotivo($request, $user)) {
            return $motivo;
        }

        return $this->csv->descargar($user);
    }

    public function consultas(): View
    {
        return view('jefe.consultas', [
            'consultas' => Consulta::query()
                ->with('viewer', 'subject')
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    /**
     * Pide el motivo antes de enseñar el registro de una persona. Una vez
     * escrito, vale para esa persona durante la sesión.
     */
    private function pedirMotivo(Request $request, User $persona): ?View
    {
        if ($request->session()->get('consulta.'.$persona->id)) {
            return null;
        }

        $request->session()->put('url.intended', url()->full());

        return view('jefe.motivo', ['persona' => $persona]);
    }

    /**
     * Las jornadas del mes agrupadas por semana, con lo registrado en cada una.
     *
     * @param  Collection<int, Jornada>  $jornadas
     * @return Collection<int, array{lunes: Carbon, domingo: Carbon, minutos: int, jornadas: Collection<int, Jornada>}>
     */
    private function porSemanas(User $persona, Collection $jornadas, Carbon $ahora): Collection
    {
        return $jornadas
            ->groupBy(fn (Jornada $jornada) => $jornada->work_date->copy()->startOfWeek(Carbon::MONDAY)->toDateString())
            ->map(fn (Collection $grupo, string $lunes) => [
                'lunes' => Carbon::parse($lunes),
                'domingo' => Carbon::parse($lunes)->endOfWeek(Carbon::SUNDAY),
                'minutos' => $grupo->sum(fn (Jornada $jornada) => $this->jornada->minutosRegistrados($persona, $jornada, $ahora)),
                'jornadas' => $grupo,
            ])
            ->values();
    }

    /**
     * @return Collection<int, Consulta>
     */
    private function consultasDe(User $persona, int $limite)
    {
        return Consulta::query()
            ->with('viewer')
            ->where('subject_id', $persona->id)
            ->latest()
            ->limit($limite)
            ->get();
    }

    /**
     * El mes pedido, si es uno en el que la persona tiene registro. Si no, el actual.
     */
    private function mesElegido(Request $request, User $persona, Carbon $ahora): Carbon
    {
        $actual = $ahora->copy()->startOfMonth();
        $pedido = (string) $request->query('mes', '');

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $pedido)) {
            return $actual;
        }

        $mes = Carbon::createFromFormat('!Y-m', $pedido, config('app.timezone'));

        return array_key_exists($mes->format('Y-m'), $this->mesesDisponibles($persona, $ahora)) ? $mes : $actual;
    }

    /**
     * Meses con registro posible, del más reciente al más antiguo. Se llega
     * hasta cuatro años atrás, el plazo de conservación del artículo 34.9.
     *
     * @return array<string, string>
     */
    private function mesesDisponibles(User $persona, Carbon $ahora): array
    {
        $primero = ($persona->starts_on ?? $ahora)->copy()->startOfMonth();
        $meses = [];

        for ($mes = $ahora->copy()->startOfMonth(); $mes->gte($primero) && count($meses) < self::MESES_QUE_SE_CONSERVAN; $mes->subMonthNoOverflow()) {
            $meses[$mes->format('Y-m')] = $mes->copy()->locale('es')->isoFormat('MMMM [de] YYYY');
        }

        return $meses ?: [$ahora->format('Y-m') => $ahora->copy()->locale('es')->isoFormat('MMMM [de] YYYY')];
    }
}
