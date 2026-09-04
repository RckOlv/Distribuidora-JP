<?php

namespace Tests\Feature;

use App\Enums\EstadoCaja;
use App\Models\Caja;
use App\Models\CajaFisica;
use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\CajaService;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Fase C-1: selección explícita de la caja física al abrir caja.
 */
class CajasDisponiblesTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Disponibilidad

    public function test_el_formulario_lista_las_cajas_activas_como_disponibles(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $fisica = CajaFisica::factory()->create(['activa' => true]);

        $props = $this->propsDePagina(
            $this->actingAs($cajero)->get(route('caja.abrir.form'))->assertOk(),
        );

        $this->assertFalse($props['hay_caja_abierta']);

        $encontrada = collect($props['cajas_fisicas'])
            ->firstWhere('id', $fisica->id);

        $this->assertNotNull($encontrada, 'La caja física activa debería listarse.');
        $this->assertSame($fisica->nombre, $encontrada['nombre']);
        $this->assertTrue($encontrada['disponible']);
    }

    public function test_la_caja_fisica_ocupada_por_otra_sesion_abierta_no_esta_disponible(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $fisica = CajaFisica::factory()->create(['activa' => true]);

        // Otro usuario abre la caja física → queda ocupada.
        $otro = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        app(CajaService::class)->abrir($otro, 1000, $fisica);

        $props = $this->propsDePagina(
            $this->actingAs($cajero)->get(route('caja.abrir.form'))->assertOk(),
        );

        $encontrada = collect($props['cajas_fisicas'])
            ->firstWhere('id', $fisica->id);

        $this->assertNotNull($encontrada, 'La caja física debería listarse.');
        $this->assertFalse($encontrada['disponible'], 'La caja ocupada no debería estar disponible.');
    }

    public function test_las_cajas_inactivas_no_se_listan(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $fisica = CajaFisica::factory()->create(['activa' => true]);
        $inactiva = CajaFisica::factory()->create(['activa' => false]);

        $props = $this->propsDePagina(
            $this->actingAs($cajero)->get(route('caja.abrir.form'))->assertOk(),
        );

        $idsListados = collect($props['cajas_fisicas'])->pluck('id');
        $this->assertTrue($idsListados->contains($fisica->id));
        $this->assertFalse($idsListados->contains($inactiva->id), 'La caja inactiva no debería listarse.');
        foreach ($props['cajas_fisicas'] as $caja) {
            $this->assertTrue($caja['activa'], 'Solo deberían listarse cajas activas.');
        }
    }

    // ------------------------------------------------------------------ Validación del id

    public function test_no_se_puede_abrir_sin_especificar_una_caja_fisica(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());

        $this->actingAs($cajero)
            ->post('/caja/abrir', ['monto_inicial' => 1000])
            ->assertSessionHasErrors('caja_fisica_id');
    }

    public function test_no_se_puede_abrir_con_una_caja_fisica_inexistente(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());

        $this->actingAs($cajero)
            ->post('/caja/abrir', [
                'monto_inicial' => 1000,
                'caja_fisica_id' => 999999,
            ])
            ->assertSessionHasErrors('caja_fisica_id');
    }

    public function test_se_abre_la_caja_fisica_elegida(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $fisica = CajaFisica::factory()->create(['activa' => true]);

        $this->actingAs($cajero)
            ->post('/caja/abrir', [
                'monto_inicial' => 5000,
                'caja_fisica_id' => $fisica->id,
            ])
            ->assertRedirect(route('caja.index'));

        $this->assertDatabaseHas('cajas', [
            'caja_fisica_id' => $fisica->id,
            'usuario_abre_id' => $cajero->id,
            'estado' => EstadoCaja::ABIERTA->value,
        ]);
    }

    // ------------------------------------------------------------------ Nombre en el resumen

    public function test_el_index_muestra_el_nombre_de_la_caja_fisica(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $fisica = CajaFisica::factory()->create(['activa' => true]);
        $this->abrirCaja($cajero, 5000, $fisica);

        $props = $this->propsDePagina(
            $this->actingAs($cajero)->get(route('caja.index'))->assertOk(),
        );

        $this->assertTrue($props['caja_abierta']);
        $this->assertSame($fisica->id, $props['resumen']['caja_fisica_id']);
        $this->assertSame($fisica->nombre, $props['resumen']['caja_fisica_nombre']);
    }

    public function test_el_formulario_de_cierre_muestra_la_caja_fisica(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosCaja());
        $fisica = CajaFisica::factory()->create(['activa' => true]);
        $this->abrirCaja($cajero, 5000, $fisica);

        $props = $this->propsDePagina(
            $this->actingAs($cajero)->get(route('caja.cerrar.form'))->assertOk(),
        );

        $this->assertSame($fisica->nombre, $props['caja_fisica_nombre']);
        $this->assertSame($fisica->nombre, $props['resumen']['caja_fisica_nombre']);
    }

    public function test_el_pos_expone_la_caja_fisica_actual(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPosCaja());
        $fisica = CajaFisica::factory()->create(['activa' => true]);
        $this->abrirCaja($cajero, 5000, $fisica);

        $props = $this->propsDePagina(
            $this->actingAs($cajero)->get(route('pos.index'))->assertOk(),
        );

        $this->assertTrue($props['caja_abierta']);
        $this->assertSame($fisica->nombre, $props['caja_fisica_nombre']);
    }

    // ------------------------------------------------------------------ Helpers

    private function abrirCaja(Usuario $cajero, float $montoInicial = 0.0, ?CajaFisica $fisica = null): Caja
    {
        return app(CajaService::class)->abrir($cajero, $montoInicial, $fisica);
    }

    /**
     * Extrae las props de la página Inertia renderizada por la respuesta.
     *
     * @return array<string, mixed>
     */
    private function propsDePagina(TestResponse $response): array
    {
        $html = $response->getContent();
        if (! preg_match('/data-page="([^"]+)"/', $html, $coincidencia)) {
            $this->fail('No se encontró la página Inertia en la respuesta.');
        }

        $pagina = json_decode(html_entity_decode($coincidencia[1], ENT_QUOTES), true);

        return $pagina['props'] ?? [];
    }

    /**
     * @return list<string>
     */
    private function permisosCaja(): array
    {
        return [
            PermisosDisponibles::CAJAS_USAR,
            PermisosDisponibles::CAJAS_VER,
        ];
    }

    /**
     * @return list<string>
     */
    private function permisosPosCaja(): array
    {
        return [
            PermisosDisponibles::POS_USAR,
            PermisosDisponibles::VENTAS_REALIZAR,
            PermisosDisponibles::VENTAS_VER,
            PermisosDisponibles::CAJAS_USAR,
        ];
    }

    /**
     * @param  list<string>  $permisos
     */
    private function crearUsuarioConRol(string $nombreRol, array $permisos): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => $nombreRol]);

        $rol->permisos()->sync(
            collect($permisos)
                ->unique()
                ->map(fn (string $nombre) => Permiso::firstOrCreate(['nombre' => $nombre]))
                ->pluck('id'),
        );

        return Usuario::factory()->create(['rol_id' => $rol->id]);
    }
}
