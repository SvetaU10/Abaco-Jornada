<?php

namespace App\Services;

use App\Exceptions\ReglaJornada;
use App\Models\Aviso;
use App\Models\Correccion;
use App\Models\FalloComun;
use App\Models\Festivo;
use App\Models\Horario;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Support\Tiempo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class JornadaService
{
    public const MARGEN_AVISOS = 8;

    public const MINUTOS_HASTA_AVISO_DE_INICIO = 20;

    public const CENTRO = 'Madrid';

    public function pantalla(User $user, ?string $respuestaFestivo = null): array
    {
        $ahora = now();
        $hoy = $this->jornadaDe($user, $ahora);
        $pasada = $this->jornadaAbiertaAnterior($user, $ahora);
        $horario = $user->horarioEn($ahora);
        $esperada = $this->esDiaEsperado($user, $ahora);
        $festivo = $this->festivoDe($ahora);
        $abierto = $hoy?->tramoAbierto();
        $cierrePendiente = $this->cierrePendiente($hoy, $abierto, $horario, $esperada, $ahora);

        $estado = 'fuera';
        $estadoTexto = 'Fuera de jornada';
        $icono = 'fuera_de_jornada.svg';

        if ($pasada || $cierrePendiente) {
            $estado = 'pendiente';
            $estadoTexto = $pasada ? 'Tienes un día sin cerrar' : 'Cierre pendiente';
            $icono = 'pendiente.svg';
        } elseif ($abierto?->tipo === Tramo::TRABAJO) {
            $primero = $hoy->tramos->first(fn (Tramo $tramo) => $tramo->tipo === Tramo::TRABAJO);
            $estado = 'trabajando';
            $estadoTexto = 'Trabajando desde las '.Tiempo::hora($primero->started_at);
            $icono = 'trabajando.svg';
        } elseif ($abierto?->tipo === Tramo::PAUSA) {
            $estado = 'pausa';
            $estadoTexto = 'En pausa desde las '.Tiempo::hora($abierto->started_at);
            $icono = 'en_pausa.svg';
        } elseif ($hoy?->estaCerrada()) {
            $estado = 'cerrada';
            $estadoTexto = 'Jornada cerrada';
            $icono = 'fuera_de_jornada.svg';
        }

        $hasta = $hoy ? $this->hastaVisible($user, $hoy, $ahora) : $ahora;
        $minutosHoy = $hoy ? $this->minutosDeJornada($hoy, $hasta) : 0;
        $minutosCerrados = $hoy ? $this->minutosCerrados($hoy) : 0;
        $porEncima = $hoy ? $this->porEncima($user, $hoy, $hasta) : null;

        $banner = $this->banner($user, $ahora, $hoy, $pasada, $horario, $esperada, $abierto, $festivo, $respuestaFestivo, $cierrePendiente);

        if ($banner && $estado === 'fuera') {
            $icono = 'pendiente.svg';
        }

        $notas = [];
        if ($festivo && $respuestaFestivo === 'no') {
            $notas[] = 'Hoy es festivo en tu centro. No hay trabajo registrado.';
        }
        if ($festivo && $respuestaFestivo === 'si') {
            $notas[] = 'Has indicado que hoy hay trabajo real. El calendario sigue siendo el de tu centro.';
        }
        if ($this->ausenciaDe($user, $ahora)) {
            $notas[] = 'Hoy tienes una ausencia prevista. No hace falta fichar.';
        }

        $puedeAbrir = $user->starts_on && $ahora->toDateString() >= $user->starts_on->toDateString();
        $puedePorFestivo = ! $festivo || $respuestaFestivo === 'si';

        return [
            'fecha' => Tiempo::fecha($ahora),
            'persona' => $user->name,
            'reloj' => $ahora->format('H:i:s'),
            'serverMs' => $ahora->getTimestamp() * 1000,
            'estado' => $estado,
            'estadoTexto' => $estadoTexto,
            'icono' => $icono,
            'llevasTexto' => $hoy && ! $hoy->estaCerrada() ? 'Hoy llevas '.Tiempo::texto($minutosHoy).'.' : null,
            'minutosCerrados' => $minutosCerrados,
            'abiertoMs' => $abierto?->tipo === Tramo::TRABAJO && ! $cierrePendiente ? $abierto->started_at->getTimestamp() * 1000 : null,
            'salidaTexto' => $hoy?->estaCerrada() ? 'Salida a las '.$this->horaFin($hoy).'.' : null,
            'totalTexto' => $hoy?->estaCerrada() ? 'Hoy has trabajado '.Tiempo::texto($minutosHoy).'.' : null,
            'porEncimaTexto' => $porEncima ? 'Tiempo por encima del horario: '.Tiempo::texto($porEncima).'. No es una hora extra y no hace falta autorización.' : null,
            'horarioTexto' => $horario ? 'Tu horario es de '.$horario->texto().'.' : 'Todavía no tienes un horario asignado.',
            'comidaTexto' => $horario ? 'Tu pausa de comida es de '.$horario->comidaTexto().'.' : null,
            'notaDia' => implode(' ', $notas),
            'banner' => $banner,
            'pendiente' => $banner !== null,
            'puedeEntrar' => $puedeAbrir && $puedePorFestivo && ! $pasada && (! $hoy || $this->soloFuera($hoy)),
            'puedeSalir' => $abierto !== null && ! $pasada && ! $cierrePendiente,
            'puedePausar' => $abierto?->tipo === Tramo::TRABAJO && ! $cierrePendiente,
            'puedeVolver' => $abierto?->tipo === Tramo::PAUSA && ! $cierrePendiente,
            'puedeYaEstaba' => $puedeAbrir && $puedePorFestivo && ! $pasada && (! $hoy || $this->soloFuera($hoy)),
            'puedeFuera' => $puedeAbrir && ! $pasada && (! $festivo || $respuestaFestivo === 'si'),
            'hoyTexto' => Tiempo::corto($minutosHoy),
            'semanaTexto' => Tiempo::corto($this->minutosSemana($user, $ahora)),
            'esResponsable' => $user->esResponsable(),
        ];
    }

    public function entrar(User $user): string
    {
        $ahora = now();

        try {
            DB::transaction(function () use ($user, $ahora) {
                $this->bloquear($user);
                $this->asegurarPuedeAbrir($user, $ahora);
                $jornada = $this->jornadaParaAbrir($user, $ahora);
                $this->abrirTramo($jornada, Tramo::TRABAJO, $ahora, $ahora, false, 'ahora');
                $this->responder($user, 'inicio', $ahora);
            });
        } catch (UniqueConstraintViolationException) {
            $this->avisarJornadaYaEmpezada();
        }

        return 'Guardado. Entrada a las '.Tiempo::hora($ahora).'.';
    }

    public function yaEstaba(User $user, string $hora): string
    {
        $ahora = now();
        $inicio = $this->momento($ahora, $hora);

        if ($inicio->greaterThan($ahora)) {
            throw new ReglaJornada('Esa hora todavía no ha llegado.');
        }

        try {
            DB::transaction(function () use ($user, $ahora, $inicio) {
                $this->bloquear($user);
                $pasada = $this->jornadaAbiertaAnterior($user, $ahora);
                if ($pasada) {
                    throw new ReglaJornada($this->textoOlvido($pasada));
                }

                $hoy = $this->jornadaDe($user, $ahora);
                if (! $hoy || $this->soloFuera($hoy)) {
                    $this->asegurarFechaDeAlta($user, $ahora);
                    $jornada = $hoy ?? Jornada::create([
                        'user_id' => $user->id,
                        'work_date' => $ahora->toDateString(),
                    ]);
                    if ($hoy && $this->solapa($hoy, $inicio, $ahora)) {
                        throw new ReglaJornada('Esa hora se cruza con un tramo que ya está guardado.');
                    }
                    $this->abrirTramo($jornada, Tramo::TRABAJO, $inicio, $ahora, false, 'hora_real');
                    $this->responder($user, 'inicio', $ahora);

                    return;
                }

                if ($hoy->estaCerrada()) {
                    throw new ReglaJornada('Hoy ya has cerrado la jornada.');
                }

                $primero = $hoy->tramos()->where('tipo', Tramo::TRABAJO)->orderBy('started_at')->first();
                if (! $primero) {
                    throw new ReglaJornada('Hoy no hay un tramo de trabajo que mover.');
                }
                if ($inicio->greaterThanOrEqualTo($primero->started_at)) {
                    throw new ReglaJornada('Elige una hora anterior a las '.Tiempo::hora($primero->started_at).'.');
                }

                $this->anotar($user, $primero, 'started_at', $primero->started_at, $inicio, 'antes', null);
                $primero->update(['started_at' => $inicio]);
                $this->responder($user, 'inicio', $ahora);
            });
        } catch (UniqueConstraintViolationException) {
            $this->avisarJornadaYaEmpezada();
        }

        return 'Guardado. Trabajas desde las '.Tiempo::hora($inicio).'.';
    }

    public function pausar(User $user): string
    {
        $ahora = now();

        DB::transaction(function () use ($user, $ahora) {
            $this->bloquear($user);
            $abierto = $this->tramoAbiertoDeHoy($user, $ahora);
            if ($abierto?->tipo !== Tramo::TRABAJO) {
                throw new ReglaJornada('La pausa se marca cuando estás trabajando.');
            }
            $abierto->update(['ended_at' => $ahora]);
            $this->abrirTramo($abierto->jornada, Tramo::PAUSA, $ahora);
        });

        return 'Guardado. Pausa a las '.Tiempo::hora($ahora).'.';
    }

    public function volver(User $user): string
    {
        $ahora = now();

        DB::transaction(function () use ($user, $ahora) {
            $this->bloquear($user);
            $abierto = $this->tramoAbiertoDeHoy($user, $ahora);
            if ($abierto?->tipo !== Tramo::PAUSA) {
                throw new ReglaJornada('No hay una pausa abierta.');
            }
            $abierto->update(['ended_at' => $ahora]);
            $this->abrirTramo($abierto->jornada, Tramo::TRABAJO, $ahora);
        });

        return 'Guardado. Sigues desde las '.Tiempo::hora($ahora).'.';
    }

    public function salir(User $user): string
    {
        $ahora = now();

        DB::transaction(function () use ($user, $ahora) {
            $this->bloquear($user);
            $abierto = $this->tramoAbiertoDeHoy($user, $ahora);
            if (! $abierto) {
                throw new ReglaJornada('No hay una jornada abierta.');
            }
            $this->declararSalida($abierto, $user, $ahora, $ahora);
            $this->responder($user, 'cierre', $ahora);
        });

        return 'Guardado. Salida a las '.Tiempo::hora($ahora).'.';
    }

    public function reunion(User $user): string
    {
        $ahora = now();
        $horario = $user->horarioEn($ahora);
        if (! $horario) {
            throw new ReglaJornada('Todavía no tienes un horario asignado.');
        }
        $inicio = $this->momento($ahora, $horario->corta($horario->morning_start));
        if ($inicio->greaterThan($ahora)) {
            throw new ReglaJornada('Esa hora todavía no ha llegado.');
        }

        try {
            DB::transaction(function () use ($user, $ahora, $inicio) {
                $this->bloquear($user);
                $this->asegurarPuedeAbrir($user, $ahora);
                $jornada = $this->jornadaParaAbrir($user, $ahora);
                $jornada->load('tramos');
                if ($this->solapa($jornada, $inicio, $ahora)) {
                    throw new ReglaJornada('Esa hora se cruza con un tramo que ya está guardado.');
                }
                $this->abrirTramo($jornada, Tramo::TRABAJO, $inicio, $ahora, false, 'reunion');
                $this->responder($user, 'inicio', $ahora);
            });
        } catch (UniqueConstraintViolationException) {
            $this->avisarJornadaYaEmpezada();
        }

        return 'Guardado. Inicio a las '.Tiempo::hora($inicio).'. Lo anotaste a las '.Tiempo::hora($ahora).'.';
    }

    public function cerrarALas(User $user, string $hora): string
    {
        $ahora = now();
        $fin = $this->momento($ahora, $hora);

        DB::transaction(function () use ($user, $ahora, $fin) {
            $this->bloquear($user);
            $abierto = $this->tramoAbiertoDeHoy($user, $ahora);
            if (! $abierto) {
                throw new ReglaJornada('No hay una jornada abierta.');
            }
            if ($fin->greaterThan($ahora)) {
                throw new ReglaJornada('Esa hora todavía no ha llegado.');
            }
            if ($fin->lessThanOrEqualTo($abierto->started_at)) {
                throw new ReglaJornada('La salida tiene que ser después de las '.Tiempo::hora($abierto->started_at).'.');
            }
            $this->declararSalida($abierto, $user, $fin, $ahora);
            $this->responder($user, 'cierre', $ahora);
        });

        return 'Guardado. Salida a las '.Tiempo::hora($fin).'.';
    }

    public function seguir(User $user): string
    {
        $ahora = now();

        DB::transaction(function () use ($user, $ahora) {
            $this->bloquear($user);
            $hoy = $this->jornadaDe($user, $ahora);
            if (! $hoy?->tramoAbierto()) {
                throw new ReglaJornada('No hay una jornada abierta.');
            }
            $hoy->update(['sigue' => true]);
            $this->responder($user, 'cierre', $ahora);
        });

        return 'Guardado. Sigues. La salida será la hora a la que termines.';
    }

    public function fuera(User $user, string $inicioHora, string $finHora): string
    {
        $ahora = now();
        $inicio = $this->momento($ahora, $inicioHora);
        $fin = $this->momento($ahora, $finHora);

        if ($fin->lessThanOrEqualTo($inicio)) {
            throw new ReglaJornada('La salida tiene que ser después de la entrada.');
        }
        if ($fin->greaterThan($ahora) || $inicio->greaterThan($ahora)) {
            throw new ReglaJornada('Esa hora todavía no ha llegado.');
        }

        DB::transaction(function () use ($user, $ahora, $inicio, $fin) {
            $this->bloquear($user);
            $this->asegurarFechaDeAlta($user, $ahora);
            if ($this->jornadaAbiertaAnterior($user, $ahora)) {
                throw new ReglaJornada($this->textoOlvido($this->jornadaAbiertaAnterior($user, $ahora)));
            }
            $hoy = $this->jornadaDe($user, $ahora);
            if ($hoy && $this->solapa($hoy, $inicio, $fin)) {
                throw new ReglaJornada('Esa hora se cruza con un tramo que ya está guardado.');
            }
            if (! $hoy) {
                $hoy = Jornada::create([
                    'user_id' => $user->id,
                    'work_date' => $ahora->toDateString(),
                ]);
            }
            $this->abrirTramo($hoy, Tramo::TRABAJO, $inicio, $ahora, true, 'fuera', $fin);
        });

        return 'Guardado. Trabajo fuera del equipo de las '.Tiempo::hora($inicio).' a las '.Tiempo::hora($fin).'.';
    }

    public function declararIncidencia(User $user, string $inicioHora, string $finHora, string $motivo): string
    {
        $ahora = now();
        $inicio = $this->momento($ahora, $inicioHora);
        $fin = $this->momento($ahora, $finHora);
        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new ReglaJornada('Escribe el motivo de la incidencia.');
        }
        if ($fin->lessThanOrEqualTo($inicio)) {
            throw new ReglaJornada('La salida tiene que ser después de la entrada.');
        }
        if ($fin->greaterThan($ahora) || $inicio->greaterThan($ahora)) {
            throw new ReglaJornada('Esa hora todavía no ha llegado.');
        }

        DB::transaction(function () use ($user, $ahora, $inicio, $fin, $motivo) {
            $this->bloquear($user);
            $this->asegurarFechaDeAlta($user, $ahora);
            $hoy = $this->jornadaDe($user, $ahora);
            if ($hoy && $this->solapa($hoy, $inicio, $fin)) {
                throw new ReglaJornada('Esa hora se cruza con un tramo que ya está guardado.');
            }
            if (! $hoy) {
                $hoy = Jornada::create([
                    'user_id' => $user->id,
                    'work_date' => $ahora->toDateString(),
                ]);
            }
            $this->abrirTramo($hoy, Tramo::TRABAJO, $inicio, $ahora, false, 'incidencia', $fin, $motivo);
        });

        return 'Guardado. Incidencia recibida a las '.Tiempo::hora($ahora).'. No es un fichaje en directo.';
    }

    public function excluirAvisosDeHorario(User $user, string $desde): void
    {
        Aviso::query()
            ->where('user_id', $user->id)
            ->where('cuenta', true)
            ->whereDate('work_date', '>=', $desde)
            ->update([
                'cuenta' => false,
                'exclusion' => 'horario',
            ]);
    }

    public function falloComun(string $fecha, string $nota): void
    {
        FalloComun::query()->updateOrCreate(
            ['falla_date' => $fecha],
            ['note' => $nota],
        );

        Aviso::query()
            ->whereDate('work_date', $fecha)
            ->update([
                'cuenta' => false,
                'exclusion' => 'comun',
            ]);
    }

    public function marcarExplicados(User $user, string $fecha): void
    {
        Aviso::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $fecha)
            ->whereNull('responded_at')
            ->update(['responded_at' => now()]);
    }

    public function completarOlvido(User $user, string $hora): string
    {
        $ahora = now();
        $texto = '';

        DB::transaction(function () use ($user, $hora, $ahora, &$texto) {
            $this->bloquear($user);
            $pasada = $this->jornadaAbiertaAnterior($user, $ahora);
            if (! $pasada) {
                throw new ReglaJornada('No hay un día pendiente de cerrar.');
            }
            $abierto = $pasada->tramoAbierto();
            $fin = $this->momento($pasada->work_date, $hora);
            if ($fin->greaterThan($ahora)) {
                throw new ReglaJornada('Esa hora todavía no ha llegado.');
            }
            if ($fin->lessThanOrEqualTo($abierto->started_at)) {
                throw new ReglaJornada('La salida tiene que ser después de las '.Tiempo::hora($abierto->started_at).'.');
            }
            $this->declararSalida($abierto, $user, $fin, $ahora);
            $pasada->update(['closed_late' => true]);
            $this->responder($user, 'olvido', $pasada->work_date);
            $texto = 'Guardado. Salida el '.Tiempo::fecha($pasada->work_date).' a las '.Tiempo::hora($fin).'.';
        });

        return $texto;
    }

    public function corregir(User $actor, Tramo $tramo, string $campo, string $hora, string $motivo, ?string $nota): string
    {
        if (! array_key_exists($motivo, Correccion::MOTIVOS)) {
            throw new ReglaJornada('Elige un motivo de la lista.');
        }
        if (! in_array($campo, ['started_at', 'ended_at'], true)) {
            throw new ReglaJornada('Esa hora no se puede cambiar.');
        }

        $tramo->load('jornada.user');
        $jornada = $tramo->jornada;
        $duena = $jornada->user;

        if ($actor->id !== $duena->id && ! $actor->esResponsable()) {
            throw new ReglaJornada('Solo puedes ver tu registro.');
        }
        if (! $actor->esResponsable() && $jornada->work_date->lt(now()->subDays(7)->startOfDay())) {
            throw new ReglaJornada('Para cambiar este día, habla con tu responsable.');
        }

        $nota = $nota !== null ? trim($nota) : null;
        if ($nota === '') {
            $nota = null;
        }

        $guardada = null;

        DB::transaction(function () use ($actor, $tramo, $jornada, $duena, $campo, $hora, $motivo, $nota, &$guardada) {
            $this->bloquear($duena);
            $fresco = Tramo::query()->whereKey($tramo->id)->lockForUpdate()->first();
            if (! $fresco) {
                throw new ReglaJornada('Ese tramo ya no está.');
            }

            $anterior = $fresco->{$campo};
            if (! $anterior) {
                throw new ReglaJornada('Para cerrar la jornada usa Salida.');
            }

            $nueva = $this->momento($jornada->work_date, $hora);
            if ($nueva->greaterThan(now())) {
                throw new ReglaJornada('Esa hora todavía no ha llegado.');
            }
            if ($nueva->equalTo($anterior)) {
                throw new ReglaJornada('Esa hora ya es la que está guardada.');
            }

            $inicio = $campo === 'started_at' ? $nueva : $fresco->started_at;
            $fin = $campo === 'ended_at' ? $nueva : $fresco->ended_at;
            if (! $fin || $fin->lessThanOrEqualTo($inicio)) {
                throw new ReglaJornada('La salida tiene que ser después de la entrada.');
            }

            $jornada->unsetRelation('tramos');
            $jornada->load('tramos');
            if ($this->solapa($jornada, $inicio, $fin, $fresco->id)) {
                throw new ReglaJornada('Esa hora se cruza con un tramo que ya está guardado.');
            }

            $this->anotar($actor, $fresco, $campo, $anterior, $nueva, $motivo, $nota);
            $fresco->update([$campo => $nueva]);
            $guardada = $nueva;
        });

        return 'Guardado. La hora ha quedado en las '.Tiempo::hora($guardada).'.';
    }

    public function minutosDeJornada(Jornada $jornada, ?Carbon $hasta = null): int
    {
        $hasta ??= now();
        $total = 0;

        foreach ($jornada->tramos as $tramo) {
            if ($tramo->tipo !== Tramo::TRABAJO) {
                continue;
            }
            $fin = $tramo->ended_at ?? $hasta;
            if ($fin->lessThan($tramo->started_at)) {
                continue;
            }
            $total += (int) round($tramo->started_at->diffInMinutes($fin, true));
        }

        return $total;
    }

    public function porEncima(User $user, Jornada $jornada, ?Carbon $hasta = null): ?int
    {
        if (! $this->esDiaEsperado($user, $jornada->work_date)) {
            return null;
        }
        $horario = $user->horarioEn($jornada->work_date);
        if (! $horario) {
            return null;
        }
        $hecho = $this->minutosDeJornada($jornada, $hasta);
        $previsto = $this->minutosPrevistos($horario);
        $diferencia = $hecho - $previsto;

        return $diferencia > 0 ? $diferencia : null;
    }

    public function esDiaEsperado(User $user, Carbon $fecha): bool
    {
        if (! $user->starts_on || $fecha->toDateString() < $user->starts_on->toDateString()) {
            return false;
        }
        if ($fecha->dayOfWeekIso >= 6) {
            return false;
        }
        if ($this->festivoDe($fecha) || $this->ausenciaDe($user, $fecha)) {
            return false;
        }

        return true;
    }

    public function problemasSinExplicar(User $user, Carbon $ahora): int
    {
        $desde = $ahora->copy()->subDays(28)->toDateString();

        return Aviso::query()
            ->where('user_id', $user->id)
            ->where('cuenta', true)
            ->whereNull('responded_at')
            ->whereDate('work_date', '>=', $desde)
            ->whereDate('work_date', '<=', $ahora->toDateString())
            ->count();
    }

    public function anioSinCalendario(Carbon $ahora): bool
    {
        return ! Festivo::query()->whereYear('holiday_date', $ahora->year)->exists();
    }

    /**
     * Cómo está hoy una persona, solo para consultar. No abre jornadas ni
     * genera avisos: mirar a alguien no puede cambiar nada de lo suyo.
     *
     * @return array{estado: string, texto: string, icono: string}
     */
    public function estadoDe(User $user, ?Carbon $ahora = null): array
    {
        $ahora ??= now();

        if (! $user->active) {
            return $this->estadoDeConsulta('cerrado', 'Acceso cerrado', 'fuera_de_jornada.svg');
        }

        if (! $user->starts_on || $ahora->toDateString() < $user->starts_on->toDateString()) {
            return $this->estadoDeConsulta('sin_empezar', 'Su registro todavía no ha empezado', 'fuera_de_jornada.svg');
        }

        $hoy = $this->jornadaDe($user, $ahora);
        $abierto = $hoy?->tramoAbierto();
        $horario = $user->horarioEn($ahora);
        $esperada = $this->esDiaEsperado($user, $ahora);

        if ($this->jornadaAbiertaAnterior($user, $ahora)) {
            return $this->estadoDeConsulta('pendiente', 'Tiene un día anterior sin cerrar', 'pendiente.svg');
        }
        if ($this->cierrePendiente($hoy, $abierto, $horario, $esperada, $ahora)) {
            return $this->estadoDeConsulta('pendiente', 'Cierre pendiente', 'pendiente.svg');
        }
        if ($abierto?->tipo === Tramo::TRABAJO) {
            return $this->estadoDeConsulta('trabajando', 'Trabajando', 'trabajando.svg');
        }
        if ($abierto?->tipo === Tramo::PAUSA) {
            return $this->estadoDeConsulta('pausa', 'En pausa', 'en_pausa.svg');
        }
        if ($hoy?->estaCerrada()) {
            return $this->estadoDeConsulta('cerrada', 'Jornada cerrada', 'fuera_de_jornada.svg');
        }
        if ($this->ausenciaDe($user, $ahora)) {
            return $this->estadoDeConsulta('ausencia', 'Ausencia prevista', 'fuera_de_jornada.svg');
        }
        if ($this->festivoDe($ahora)) {
            return $this->estadoDeConsulta('festivo', 'Festivo en su centro', 'fuera_de_jornada.svg');
        }
        if ($esperada) {
            return $this->estadoDeConsulta('sin_empezar_hoy', 'Todavía no ha empezado', 'fuera_de_jornada.svg');
        }

        return $this->estadoDeConsulta('fuera', 'Fuera de jornada', 'fuera_de_jornada.svg');
    }

    /**
     * Minutos de una jornada tal como están guardados. Un tramo que quedó
     * abierto en un día pasado no sigue sumando con el reloj: no se sabe a
     * qué hora terminó, así que no se inventa.
     */
    public function minutosRegistrados(User $user, Jornada $jornada, ?Carbon $ahora = null): int
    {
        return $this->minutosDeJornada($jornada, $this->hastaDeConsulta($user, $jornada, $ahora ?? now()));
    }

    /**
     * Tiempo por encima del horario de una jornada, con el mismo criterio que
     * {@see minutosRegistrados()}.
     */
    public function porEncimaRegistrado(User $user, Jornada $jornada, ?Carbon $ahora = null): ?int
    {
        return $this->porEncima($user, $jornada, $this->hastaDeConsulta($user, $jornada, $ahora ?? now()));
    }

    private function hastaDeConsulta(User $user, Jornada $jornada, Carbon $ahora): Carbon
    {
        $abierto = $jornada->tramoAbierto();

        if ($abierto && ! $jornada->work_date->isSameDay($ahora)) {
            return $abierto->started_at;
        }

        return $this->hastaVisible($user, $jornada, $ahora);
    }

    /**
     * Totales de un mes para consultar: lo registrado, lo previsto hasta hoy y
     * el tiempo por encima del horario. Ese tiempo no se clasifica como hora
     * extra: lo decide Ábaco.
     *
     * @param  Collection<int, Jornada>  $jornadas  Jornadas de ese mes, con sus tramos.
     * @return array{registrados: int, previstos: int, porEncima: int, dias: int}
     */
    public function resumenDelMes(User $user, Carbon $mes, Collection $jornadas, ?Carbon $ahora = null): array
    {
        $ahora ??= now();
        $hasta = $mes->copy()->endOfMonth()->min($ahora);
        $previstos = 0;

        for ($dia = $mes->copy()->startOfMonth(); $dia->lte($hasta); $dia->addDay()) {
            $horario = $user->horarioEn($dia);
            if ($horario && $this->esDiaEsperado($user, $dia)) {
                $previstos += $this->minutosPrevistos($horario);
            }
        }

        return [
            'registrados' => $jornadas->sum(fn (Jornada $jornada) => $this->minutosRegistrados($user, $jornada, $ahora)),
            'previstos' => $previstos,
            'porEncima' => $jornadas->sum(fn (Jornada $jornada) => $this->porEncimaRegistrado($user, $jornada, $ahora) ?? 0),
            'dias' => $jornadas->count(),
        ];
    }

    /**
     * @return array{estado: string, texto: string, icono: string}
     */
    private function estadoDeConsulta(string $estado, string $texto, string $icono): array
    {
        return ['estado' => $estado, 'texto' => $texto, 'icono' => $icono];
    }

    private function banner(
        User $user,
        Carbon $ahora,
        ?Jornada $hoy,
        ?Jornada $pasada,
        ?Horario $horario,
        bool $esperada,
        ?Tramo $abierto,
        bool $festivo,
        ?string $respuestaFestivo,
        bool $cierrePendiente,
    ): ?array {
        if ($pasada) {
            $this->registrarAviso($user, 'olvido', $pasada->work_date);

            return [
                'texto' => $this->textoOlvido($pasada),
                'accion' => 'completar',
                'min' => Tiempo::hora($pasada->tramoAbierto()->started_at),
                'max' => '23:59',
            ];
        }

        if ($cierrePendiente && $horario) {
            $this->registrarAviso($user, 'cierre', $ahora);
            $fin = $horario->corta($horario->afternoon_end);

            return [
                'texto' => 'Son las '.$fin.'. ¿Terminas ya?',
                'accion' => 'cierre',
                'hora' => $fin,
            ];
        }

        if ($festivo && (! $hoy || $this->soloFuera($hoy)) && $respuestaFestivo === null) {
            return [
                'texto' => 'Hoy es festivo en tu centro. Encender el equipo no cuenta como trabajo. Seguimos el calendario del centro. ¿Hay trabajo real?',
                'accion' => 'festivo',
            ];
        }

        $puedePreguntarInicio = $esperada || $respuestaFestivo === 'si';
        if ((! $hoy || $this->soloFuera($hoy)) && $puedePreguntarInicio && $horario && $ahora->greaterThan($this->momento($ahora, $horario->corta($horario->morning_start)))) {
            $this->registrarAviso($user, 'inicio', $ahora);
            $prevista = $horario->corta($horario->morning_start);

            return [
                'texto' => 'Tu jornada empezaba a las '.$prevista.'. ¿Ya estabas trabajando?',
                'accion' => 'inicio',
                'hora' => $prevista,
            ];
        }

        $reiteracion = $this->problemasSinExplicar($user, $ahora);
        if ($reiteracion >= self::MARGEN_AVISOS) {
            return [
                'texto' => 'Este mes tienes '.$reiteracion.' cierres sin completar. Puedes revisarlos o explicarlos aquí.',
                'accion' => 'registro',
            ];
        }

        if ($user->esResponsable() && $this->anioSinCalendario($ahora)) {
            return [
                'texto' => 'El calendario de '.$ahora->year.' no está cargado. No inventamos festivos. Los días ya trabajados se quedan como se registraron.',
                'accion' => 'calendario',
            ];
        }

        return null;
    }

    private function asegurarPuedeAbrir(User $user, Carbon $ahora): void
    {
        $this->asegurarFechaDeAlta($user, $ahora);
        $pasada = $this->jornadaAbiertaAnterior($user, $ahora);
        if ($pasada) {
            throw new ReglaJornada($this->textoOlvido($pasada));
        }
        $hoy = $this->jornadaDe($user, $ahora);
        if ($hoy && ! $this->soloFuera($hoy)) {
            if ($hoy->estaCerrada()) {
                throw new ReglaJornada('Hoy ya has cerrado la jornada.');
            }
            throw new ReglaJornada('Hoy ya has empezado la jornada.');
        }
    }

    private function jornadaParaAbrir(User $user, Carbon $ahora): Jornada
    {
        return $this->jornadaDe($user, $ahora) ?? Jornada::create([
            'user_id' => $user->id,
            'work_date' => $ahora->toDateString(),
        ]);
    }

    private function soloFuera(Jornada $jornada): bool
    {
        return $jornada->estaCerrada()
            && $jornada->tramos->isNotEmpty()
            && $jornada->tramos->every(fn (Tramo $tramo) => $tramo->fuera_del_equipo || $tramo->situacion === 'incidencia');
    }

    private function cierrePendiente(?Jornada $hoy, ?Tramo $abierto, ?Horario $horario, bool $esperada, Carbon $ahora): bool
    {
        if ($hoy === null || $abierto === null || $horario === null || $hoy->sigue || ! $esperada) {
            return false;
        }

        $fin = $this->momento($hoy->work_date, $horario->corta($horario->afternoon_end));

        return $abierto->started_at->lessThan($fin)
            && $this->pasoLaHora($ahora, $horario->corta($horario->afternoon_end));
    }

    private function hastaVisible(User $user, Jornada $jornada, Carbon $ahora): Carbon
    {
        $abierto = $jornada->tramoAbierto();
        $horario = $user->horarioEn($jornada->work_date);
        if ($this->cierrePendiente($jornada, $abierto, $horario, $this->esDiaEsperado($user, $jornada->work_date), $ahora) && $horario) {
            return $this->momento($jornada->work_date, $horario->corta($horario->afternoon_end));
        }

        return $ahora;
    }

    private function solapa(Jornada $jornada, Carbon $inicio, Carbon $fin, ?int $excepto = null): bool
    {
        foreach ($jornada->tramos as $tramo) {
            if ($excepto !== null && $tramo->id === $excepto) {
                continue;
            }
            $tramoFin = $tramo->ended_at ?? now();
            if ($inicio->lt($tramoFin) && $fin->gt($tramo->started_at)) {
                return true;
            }
        }

        return false;
    }

    private function asegurarFechaDeAlta(User $user, Carbon $ahora): void
    {
        if (! $user->starts_on || $ahora->toDateString() < $user->starts_on->toDateString()) {
            $fecha = $user->starts_on ? Tiempo::fecha($user->starts_on) : 'más adelante';
            throw new ReglaJornada('Tu registro empieza el '.$fecha.'.');
        }
    }

    private function jornadaDe(User $user, Carbon $fecha): ?Jornada
    {
        return Jornada::query()
            ->with('tramos')
            ->where('user_id', $user->id)
            ->whereDate('work_date', $fecha->toDateString())
            ->first();
    }

    private function jornadaAbiertaAnterior(User $user, Carbon $ahora): ?Jornada
    {
        return Jornada::query()
            ->with('tramos')
            ->where('user_id', $user->id)
            ->whereDate('work_date', '<', $ahora->toDateString())
            ->whereHas('tramos', fn ($q) => $q->whereNull('ended_at'))
            ->orderBy('work_date')
            ->first();
    }

    private function tramoAbiertoDeHoy(User $user, Carbon $ahora): ?Tramo
    {
        $hoy = $this->jornadaDe($user, $ahora);

        return $hoy?->tramoAbierto();
    }

    private function abrirTramo(
        Jornada $jornada,
        string $tipo,
        Carbon $inicio,
        ?Carbon $anotado = null,
        bool $fuera = false,
        ?string $situacion = null,
        ?Carbon $fin = null,
        ?string $nota = null,
    ): void {
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => $tipo,
            'started_at' => $inicio,
            'ended_at' => $fin,
            'anotado_at' => $anotado ?? $inicio,
            'fuera_del_equipo' => $fuera,
            'situacion' => $situacion,
            'nota' => $nota,
        ]);
    }

    private function bloquear(User $user): void
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();
    }

    private function momento(Carbon $fecha, string $hora): Carbon
    {
        $hora = substr($hora, 0, 5);

        return Carbon::parse($fecha->toDateString().' '.$hora, config('app.timezone'));
    }

    private function pasoLaHora(Carbon $ahora, string $hora): bool
    {
        return $ahora->greaterThanOrEqualTo($this->momento($ahora, $hora));
    }

    private function minutosPrevistos(Horario $horario): int
    {
        return $this->duracion($horario->morning_start, $horario->morning_end)
            + $this->duracion($horario->afternoon_start, $horario->afternoon_end);
    }

    private function duracion(string $inicio, string $fin): int
    {
        $desde = Carbon::parse(substr($inicio, 0, 5));
        $hasta = Carbon::parse(substr($fin, 0, 5));

        return (int) round($desde->diffInMinutes($hasta, true));
    }

    private function minutosCerrados(Jornada $jornada): int
    {
        $total = 0;
        foreach ($jornada->tramos as $tramo) {
            if ($tramo->tipo !== Tramo::TRABAJO || $tramo->ended_at === null) {
                continue;
            }
            $total += (int) round($tramo->started_at->diffInMinutes($tramo->ended_at, true));
        }

        return $total;
    }

    private function minutosSemana(User $user, Carbon $ahora): int
    {
        $lunes = $ahora->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $domingo = $ahora->copy()->endOfWeek(Carbon::SUNDAY)->toDateString();
        $jornadas = Jornada::query()
            ->with('tramos')
            ->where('user_id', $user->id)
            ->whereBetween('work_date', [$lunes, $domingo])
            ->get();

        return $jornadas->sum(fn (Jornada $jornada) => $this->minutosDeJornada($jornada, $this->hastaVisible($user, $jornada, $ahora)));
    }

    private function horaFin(Jornada $jornada): string
    {
        $ultimo = $jornada->tramos->filter(fn (Tramo $tramo) => $tramo->ended_at !== null)->sortBy('ended_at')->last();

        return $ultimo ? Tiempo::hora($ultimo->ended_at) : '';
    }

    private function festivoDe(Carbon $fecha): bool
    {
        return Festivo::query()
            ->whereDate('holiday_date', $fecha->toDateString())
            ->where('municipality', self::CENTRO)
            ->exists();
    }

    private function ausenciaDe(User $user, Carbon $fecha): bool
    {
        return $user->ausencias()->whereDate('absence_date', $fecha->toDateString())->exists();
    }

    private function textoOlvido(Jornada $jornada): string
    {
        $dia = $jornada->work_date->locale('es')->isoFormat('dddd');

        return 'No cerraste el '.$dia.'. ¿A qué hora terminaste?';
    }

    /**
     * Decide si hoy toca mandar por correo el aviso de que la jornada no ha
     * empezado. Pasa si es un día de trabajo, ya han pasado unos minutos desde
     * la hora de inicio, el horario no ha terminado y no hay nada anotado.
     * Deja el aviso guardado y reserva el envío: devuelve la hora prevista
     * solo la primera vez, para que un segundo intento no repita el correo.
     */
    public function avisarDeInicio(User $user, ?Carbon $ahora = null): ?string
    {
        $ahora ??= now();
        $horario = $user->horarioEn($ahora);

        if (! $user->active || $user->esJefe() || ! $horario || ! $this->esDiaEsperado($user, $ahora)) {
            return null;
        }

        $hoy = $this->jornadaDe($user, $ahora);
        if ($hoy && $hoy->tramos->contains(fn (Tramo $tramo) => $tramo->situacion === 'incidencia')) {
            return null;
        }
        if ($hoy && ! $this->soloFuera($hoy)) {
            return null;
        }

        $prevista = $horario->corta($horario->morning_start);
        $aviso = $this->momento($ahora, $prevista)->addMinutes(self::MINUTOS_HASTA_AVISO_DE_INICIO);
        $cierre = $this->momento($ahora, $horario->corta($horario->afternoon_end));
        if ($ahora->lessThan($aviso) || $ahora->greaterThanOrEqualTo($cierre)) {
            return null;
        }

        $this->registrarAviso($user, 'inicio', $ahora);

        $reservado = Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'inicio')
            ->whereDate('work_date', $ahora->toDateString())
            ->whereNull('correo_enviado_at')
            ->update(['correo_enviado_at' => $ahora]);

        return $reservado === 1 ? $prevista : null;
    }

    /**
     * Deshace la reserva si el correo no salió, para reintentarlo en la
     * siguiente pasada.
     */
    public function liberarAvisoDeInicio(User $user, Carbon $ahora): void
    {
        Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', 'inicio')
            ->whereDate('work_date', $ahora->toDateString())
            ->update(['correo_enviado_at' => null]);
    }

    private function registrarAviso(User $user, string $tipo, Carbon $fecha): void
    {
        $yaExiste = Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', $tipo)
            ->whereDate('work_date', $fecha->toDateString())
            ->exists();

        if ($yaExiste) {
            return;
        }

        $comun = FalloComun::query()->whereDate('falla_date', $fecha->toDateString())->exists();

        try {
            Aviso::query()->create([
                'user_id' => $user->id,
                'tipo' => $tipo,
                'work_date' => $fecha->toDateString(),
                'cuenta' => ! $comun,
                'exclusion' => $comun ? 'comun' : null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otro proceso guardó el mismo aviso a la vez: no hay nada que hacer.
        }
    }

    private function responder(User $user, string $tipo, Carbon $fecha): void
    {
        Aviso::query()
            ->where('user_id', $user->id)
            ->where('tipo', $tipo)
            ->whereDate('work_date', $fecha->toDateString())
            ->whereNull('responded_at')
            ->update(['responded_at' => now()]);
    }

    private function declararSalida(Tramo $tramo, User $user, Carbon $fin, Carbon $declarada): void
    {
        $datos = ['ended_at' => $fin];

        if ($tramo->cerrado_at === null) {
            $datos['cerrado_por'] = $user->id;
            $datos['cerrado_at'] = $declarada;
        }

        $tramo->update($datos);
    }

    private function anotar(User $actor, Tramo $tramo, string $campo, Carbon $anterior, Carbon $nueva, string $motivo, ?string $nota): void
    {
        Correccion::create([
            'tramo_id' => $tramo->id,
            'user_id' => $actor->id,
            'field' => $campo,
            'previous_value' => $anterior,
            'new_value' => $nueva,
            'reason' => $motivo,
            'note' => $nota,
        ]);
    }

    private function avisarJornadaYaEmpezada(): never
    {
        throw new ReglaJornada('Hoy ya has empezado la jornada.');
    }
}
