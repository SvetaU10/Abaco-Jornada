<?php

namespace App\Services;

use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use App\Support\Tiempo;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Copia del registro de una persona en CSV. La comparten quien consulta
 * su propio registro y quien lo consulta con motivo.
 */
class RegistroCsv
{
    public function __construct(private JornadaService $jornada) {}

    public function descargar(User $persona): StreamedResponse
    {
        $jornadas = Jornada::query()
            ->with('tramos.correcciones.autor')
            ->where('user_id', $persona->id)
            ->orderBy('work_date')
            ->get();

        $nombre = 'registro-'.$persona->id.'.csv';

        return response()->streamDownload(function () use ($jornadas, $persona) {
            $salida = fopen('php://output', 'w');
            fwrite($salida, "\xEF\xBB\xBF");
            fputcsv($salida, [
                'Fecha',
                'Inicio',
                'Fin',
                'Inicio anterior',
                'Fin anterior',
                'Cambiado por',
                'Cambiado el',
                'Anotado a las',
                'Fuera del equipo',
                'Pausas',
                'Total',
                'Por encima del horario',
            ], ';');

            foreach ($jornadas as $jornada) {
                $trabajo = $jornada->tramos->where('tipo', Tramo::TRABAJO);
                $pausas = $jornada->tramos->where('tipo', Tramo::PAUSA);
                $primero = $trabajo->first();
                $ultimo = $jornada->tramos->filter(fn (Tramo $tramo) => $tramo->ended_at)->sortBy('ended_at')->last();
                $porEncima = $this->jornada->porEncimaRegistrado($persona, $jornada);
                $correcciones = $jornada->tramos->flatMap->correcciones->sortBy('created_at');
                $inicioAnterior = $correcciones->first(fn ($correccion) => $correccion->field === 'started_at');
                $finAnterior = $correcciones->first(fn ($correccion) => $correccion->field === 'ended_at');
                $ultima = $correcciones->last();

                fputcsv($salida, [
                    $jornada->work_date->format('Y-m-d'),
                    $primero?->started_at ? Tiempo::hora($primero->started_at) : '',
                    $jornada->estaCerrada() && $ultimo?->ended_at ? Tiempo::hora($ultimo->ended_at) : '',
                    $inicioAnterior ? Tiempo::hora($inicioAnterior->previous_value) : '',
                    $finAnterior ? Tiempo::hora($finAnterior->previous_value) : '',
                    $ultima?->autor?->name ?? '',
                    $ultima ? $ultima->created_at->format('Y-m-d H:i') : '',
                    $primero?->anotado_at ? Tiempo::hora($primero->anotado_at) : '',
                    $trabajo->contains(fn (Tramo $tramo) => $tramo->fuera_del_equipo) ? 'sí' : '',
                    $pausas->map(function (Tramo $tramo) {
                        $hasta = $tramo->ended_at ? Tiempo::hora($tramo->ended_at) : 'abierta';

                        return Tiempo::hora($tramo->started_at).'–'.$hasta;
                    })->implode(' | '),
                    Tiempo::texto($this->jornada->minutosRegistrados($persona, $jornada)),
                    $porEncima ? Tiempo::texto($porEncima) : '',
                ], ';');
            }

            fclose($salida);
        }, $nombre, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
