<?php

namespace Tests\Feature;

use App\Models\Aviso;
use App\Models\Horario;
use App\Models\Jornada;
use App\Models\Tramo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JornadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 10:45:00', 'Europe/Madrid'));
    }

    public function test_entrada_pausa_salida_y_no_duplica(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertRedirect()
            ->assertSessionHas('ok');

        $this->assertDatabaseCount('jornadas', 1);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('aviso');

        $this->assertDatabaseCount('jornadas', 1);
        $this->assertSame(1, Tramo::query()->count());

        $this->actingAs($ana)->post(route('jornada.pausa'))->assertSessionHas('ok');
        $this->actingAs($ana)->post(route('jornada.volver'))->assertSessionHas('ok');
        $this->assertSame(2, Tramo::query()->where('tipo', Tramo::TRABAJO)->count());

        Carbon::setTestNow(Carbon::parse('2026-10-07 18:10:00', 'Europe/Madrid'));
        $this->actingAs($ana)->post(route('jornada.salida'))->assertSessionHas('ok');

        $jornada = Jornada::query()->with('tramos')->first();
        $this->assertTrue($jornada->estaCerrada());
        $this->assertNotNull($jornada->tramos->first()->ended_at);
    }

    public function test_cierre_olvidado_permite_empezar_el_dia_nuevo(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-06',
        ]);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
        ]);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('No cerraste el martes', false);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'))
            ->assertSessionHas('aviso');

        $this->actingAs($ana)
            ->post(route('jornada.completar'), ['hora' => '18:00'])
            ->assertSessionHas('ok');

        $this->assertTrue($jornada->fresh()->closed_late);
        $this->actingAs($ana)->post(route('jornada.entrada'))->assertSessionHas('ok');
        $this->assertDatabaseCount('jornadas', 2);
    }

    public function test_una_persona_no_ve_el_registro_de_otra(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $lucia = $this->persona('Lucía Vega', 'lucia.vega@abaco.test', 'Alcalá de Henares');
        $jornada = Jornada::create([
            'user_id' => $lucia->id,
            'work_date' => '2026-10-07',
        ]);

        $this->actingAs($ana)
            ->get(route('registro.show', $jornada))
            ->assertForbidden();

        $this->actingAs($ana)
            ->get(route('equipo.index'))
            ->assertRedirect(route('jornada'));
    }

    public function test_la_trabajadora_ve_los_festivos_y_no_los_cambia(): void
    {
        $this->seed();
        $ana = User::query()->where('email', 'ana.lopez@abaco.test')->first();

        $this->actingAs($ana)
            ->get(route('calendario'))
            ->assertOk()
            ->assertSee('Calendario', false)
            ->assertSee('San Isidro', false)
            ->assertDontSee('Guardar festivo', false)
            ->assertDontSee('Marcar fallo común', false);

        $this->actingAs($ana)
            ->post(route('calendario.store'), [
                'holiday_date' => '2026-10-20',
                'name' => 'Inventado',
            ])
            ->assertRedirect(route('jornada'));

        $this->assertDatabaseMissing('festivos', ['name' => 'Inventado']);
    }

    public function test_la_baja_conserva_la_jornada(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'responsable');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-06',
        ]);

        $this->actingAs($marta)->post(route('equipo.baja', $ana))->assertSessionHas('ok');

        $this->assertFalse($ana->fresh()->active);
        $this->assertDatabaseHas('jornadas', ['user_id' => $ana->id]);
        $this->actingAsGuest()->post(route('login'), [
            'email' => 'ana.lopez@abaco.test',
            'password' => 'Jornada2026',
        ])->assertSessionHas('aviso');
    }

    public function test_quien_esta_en_otra_comunidad_sigue_el_calendario_de_madrid(): void
    {
        $this->seed();
        $otra = $this->persona('Nuria Soler', 'nuria.soler@abaco.test', 'Alcalá de Henares');
        $this->horario($otra);
        $ana = User::query()->where('email', 'ana.lopez@abaco.test')->first();

        Carbon::setTestNow(Carbon::parse('2026-05-15 10:30:00', 'Europe/Madrid'));

        $this->actingAs($otra)
            ->get(route('jornada'))
            ->assertSee('Hoy es festivo en tu centro', false)
            ->assertDontSee('¿Ya estabas trabajando?', false);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Hoy es festivo en tu centro', false)
            ->assertDontSee('¿Ya estabas trabajando?', false);
    }

    public function test_la_reunion_conserva_la_hora_prevista(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Reunión, mantener las 09:00', false);

        $this->actingAs($ana)->post(route('jornada.reunion'))->assertSessionHas('ok');

        $tramo = Tramo::query()->first();
        $this->assertSame('09:00', $tramo->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('10:45', $tramo->anotado_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('reunion', $tramo->situacion);
    }

    public function test_el_inicio_de_jornada_usa_hora_de_00_a_23(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Inicio de jornada', false)
            ->assertSee('value="23"', false)
            ->assertDontSee('>AM<', false);

        $this->actingAs($ana)
            ->post(route('jornada.entrada'), ['hora' => '08:30'])
            ->assertSessionHas('ok');

        $this->assertSame('08:30', Tramo::query()->first()->started_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_ana_y_marta_pueden_borrar_el_fichaje_de_hoy_para_probar(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'responsable');
        $lucia = $this->persona('Lucía Vega', 'lucia.vega@abaco.test', 'Alcalá de Henares');
        $deAna = Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-07',
        ]);
        Tramo::create([
            'jornada_id' => $deAna->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
            'ended_at' => '2026-10-07 11:34:00',
        ]);
        $deMarta = Jornada::create([
            'user_id' => $marta->id,
            'work_date' => '2026-10-07',
        ]);

        $this->actingAs($lucia)
            ->post(route('jornada.borrar-prueba'))
            ->assertForbidden();

        $this->actingAs($lucia)
            ->get(route('jornada'))
            ->assertDontSee('Borrar fichaje de hoy', false);

        $this->actingAs($marta)
            ->get(route('jornada'))
            ->assertSee('Borrar fichaje de hoy', false);

        $this->actingAs($ana)
            ->post(route('jornada.borrar-prueba'))
            ->assertRedirect();

        $this->assertDatabaseMissing('jornadas', ['id' => $deAna->id]);
        $this->assertDatabaseHas('jornadas', ['id' => $deMarta->id]);

        $this->actingAs($marta)
            ->post(route('jornada.borrar-prueba'))
            ->assertRedirect();

        $this->assertDatabaseCount('jornadas', 0);
        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Inicio de jornada', false);
    }

    public function test_el_equipo_encendido_no_alarga_la_jornada(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-07',
        ]);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 21:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Son las 18:00. ¿Terminas ya?', false)
            ->assertSee('9 h 00', false)
            ->assertDontSee('Terminar jornada', false)
            ->assertDontSee('12 h', false);

        $this->actingAs($ana)
            ->post(route('jornada.cerrar'), ['hora' => '18:00'])
            ->assertSessionHas('ok');

        $this->assertSame('18:00', Tramo::query()->first()->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_seguir_guarda_la_salida_real_sin_pedir_autorizacion(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create([
            'user_id' => $ana->id,
            'work_date' => '2026-10-07',
        ]);
        Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 18:35:00', 'Europe/Madrid'));
        $this->actingAs($ana)->post(route('jornada.seguir'))->assertSessionHas('ok');
        $this->actingAs($ana)
            ->post(route('jornada.salida'))
            ->assertSessionHas('ok', 'Guardado. Salida a las 18:35.');

        $this->assertSame('18:35', Tramo::query()->first()->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_el_festivo_del_centro_pregunta_si_hay_trabajo(): void
    {
        $this->seed();
        $ana = User::query()->where('email', 'ana.lopez@abaco.test')->first();
        Carbon::setTestNow(Carbon::parse('2026-05-15 10:30:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('¿Hay trabajo real?', false)
            ->assertDontSee('Empezar jornada', false);

        $this->actingAs($ana)->post(route('jornada.festivo'), ['respuesta' => 'no'])->assertSessionHas('ok');
        $this->actingAs($ana)->get(route('jornada'))->assertDontSee('Empezar jornada', false);
        $this->assertDatabaseCount('jornadas', 0);

        $this->actingAs($ana)->post(route('jornada.festivo'), ['respuesta' => 'si'])->assertSessionHas('ok');
        $this->actingAs($ana)->get(route('jornada'))->assertSee('Empezar jornada', false);
    }

    public function test_el_trabajo_fuera_del_equipo_usa_las_horas_indicadas(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        Carbon::setTestNow(Carbon::parse('2026-10-07 21:00:00', 'Europe/Madrid'));

        $this->actingAs($ana)
            ->post(route('jornada.fuera'), ['inicio' => '19:00', 'fin' => '20:00'])
            ->assertSessionHas('ok');

        $tramo = Tramo::query()->first();
        $this->assertTrue($tramo->fuera_del_equipo);
        $this->assertSame('19:00', $tramo->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('20:00', $tramo->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_por_debajo_de_ocho_avisos_el_registro_no_cambia(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-06 09:00:00',
            'ended_at' => '2026-10-06 18:00:00',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-07 08:00:00', 'Europe/Madrid'));
        foreach (range(1, 7) as $dias) {
            Aviso::create([
                'user_id' => $ana->id,
                'tipo' => 'cierre',
                'work_date' => Carbon::parse('2026-10-07')->subDays($dias)->toDateString(),
                'cuenta' => true,
            ]);
        }

        $this->actingAs($ana)->get(route('jornada'))->assertDontSee('cierres sin completar', false);
        $this->assertSame('18:00', $tramo->fresh()->ended_at->timezone('Europe/Madrid')->format('H:i'));

        Aviso::create([
            'user_id' => $ana->id,
            'tipo' => 'olvido',
            'work_date' => '2026-09-20',
            'cuenta' => true,
        ]);

        $this->actingAs($ana)->get(route('jornada'))->assertSee('Este mes tienes 8 cierres sin completar', false);
        $this->assertSame('18:00', $tramo->fresh()->ended_at->timezone('Europe/Madrid')->format('H:i'));
    }

    public function test_el_fallo_comun_y_el_horario_no_cuentan(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'responsable');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);
        $aviso = Aviso::create([
            'user_id' => $ana->id,
            'tipo' => 'inicio',
            'work_date' => '2026-10-06',
            'cuenta' => true,
        ]);

        $this->actingAs($marta)->post(route('equipo.horario', $ana), [
            'morning_start' => '08:30',
            'morning_end' => '14:00',
            'afternoon_start' => '15:00',
            'afternoon_end' => '18:00',
            'effective_from' => '2026-10-07',
        ])->assertSessionHas('ok');

        $this->assertFalse($aviso->fresh()->cuenta);
        $this->assertSame('horario', $aviso->fresh()->exclusion);

        $this->actingAs($marta)->post(route('calendario.fallo'), [
            'falla_date' => '2026-10-07',
            'note' => 'Caída del calendario',
        ])->assertSessionHas('ok');

        $this->actingAs($ana)->get(route('jornada'));
        $deHoy = Aviso::query()->where('user_id', $ana->id)->whereDate('work_date', '2026-10-07')->first();
        $this->assertNotNull($deHoy);
        $this->assertFalse($deHoy->cuenta);
        $this->assertSame('comun', $deHoy->exclusion);
    }

    public function test_la_consulta_pide_motivo_y_queda_escrita(): void
    {
        $marta = $this->persona('Marta Ruiz', 'marta.ruiz@abaco.test', 'Madrid', 'responsable');
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-06']);

        $this->actingAs($marta)
            ->get(route('equipo.registro', $ana))
            ->assertSee('hace falta un motivo', false)
            ->assertDontSee('Descargar copia', false);

        $this->actingAs($marta)
            ->post(route('equipo.motivo', $ana), ['reason' => 'Revisión del artículo 34.9'])
            ->assertRedirect();

        $this->actingAs($marta)
            ->get(route('equipo.registro', $ana))
            ->assertSee('Descargar copia', false);

        $this->assertDatabaseHas('consultas', [
            'viewer_id' => $marta->id,
            'subject_id' => $ana->id,
            'reason' => 'Revisión del artículo 34.9',
        ]);
    }

    public function test_cambiar_una_hora_conserva_la_anterior(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $jornada = Jornada::create(['user_id' => $ana->id, 'work_date' => '2026-10-07']);
        $tramo = Tramo::create([
            'jornada_id' => $jornada->id,
            'tipo' => Tramo::TRABAJO,
            'started_at' => '2026-10-07 09:30:00',
            'ended_at' => '2026-10-07 18:00:00',
        ]);

        $this->actingAs($ana)->post(route('tramos.corregir', $tramo), [
            'campo' => 'started_at',
            'hora' => '09:00',
            'motivo' => 'hora',
        ])->assertSessionHas('ok');

        $this->assertSame('09:00', $tramo->fresh()->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertDatabaseHas('correcciones', [
            'tramo_id' => $tramo->id,
            'user_id' => $ana->id,
        ]);
        $this->actingAs($ana)
            ->get(route('registro.show', $jornada))
            ->assertSee('Antes 09:30', false)
            ->assertSee('ahora 09:00', false);
    }

    public function test_la_incidencia_de_conexion_declara_horas_sin_fichar_en_directo(): void
    {
        $ana = $this->persona('Ana López', 'ana.lopez@abaco.test', 'Madrid');
        $this->horario($ana);

        $this->actingAs($ana)
            ->post(route('jornada.incidencia'), [
                'inicio' => '09:00',
                'fin' => '10:30',
                'motivo' => 'Se cayó la red',
            ])
            ->assertSessionHas('ok');

        $tramo = Tramo::query()->first();
        $this->assertNotNull($tramo->ended_at);
        $this->assertSame('incidencia', $tramo->situacion);
        $this->assertSame('09:00', $tramo->started_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('10:30', $tramo->ended_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('10:45', $tramo->anotado_at->timezone('Europe/Madrid')->format('H:i'));
        $this->assertSame('Se cayó la red', $tramo->nota);
        $this->assertFalse($tramo->fuera_del_equipo);
        $this->assertDatabaseCount('avisos', 0);

        $this->actingAs($ana)
            ->get(route('registro.show', $tramo->jornada_id))
            ->assertSee('incidencia de conexión', false)
            ->assertSee('Se cayó la red', false)
            ->assertSee('Ana López', false);

        $this->actingAs($ana)
            ->get(route('jornada'))
            ->assertSee('Empezar jornada', false)
            ->assertSee('No guardado: sin conexión', false);
    }

    private function persona(string $nombre, string $correo, string $municipio, string $papel = 'trabajadora'): User
    {
        return User::create([
            'name' => $nombre,
            'email' => $correo,
            'password' => 'Jornada2026',
            'role' => $papel,
            'active' => true,
            'starts_on' => '2026-01-07',
            'municipality' => $municipio,
        ]);
    }

    private function horario(User $user): void
    {
        Horario::create([
            'user_id' => $user->id,
            'morning_start' => '09:00',
            'morning_end' => '14:00',
            'afternoon_start' => '15:00',
            'afternoon_end' => '18:00',
            'effective_from' => '2026-01-07',
        ]);
    }
}
