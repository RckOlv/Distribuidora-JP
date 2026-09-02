<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AutorizacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitado_es_redirigido_a_login(): void
    {
        $this->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_dueno_tiene_acceso_completo_a_todos_los_permisos(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        foreach (Permisos::todos() as $permiso) {
            $this->assertTrue($dueno->can($permiso), "El Dueño debería poder: {$permiso}");
        }
    }

    public function test_cajero_solo_tiene_permisos_de_punto_de_venta(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        foreach ($this->permisosPos() as $permiso) {
            $this->assertTrue($cajero->can($permiso), "El Cajero debería poder: {$permiso}");
        }

        foreach (array_diff(Permisos::todos(), $this->permisosPos()) as $permiso) {
            $this->assertFalse($cajero->can($permiso), "El Cajero no debería poder: {$permiso}");
        }
    }

    public function test_cajero_no_puede_acceder_por_url_a_rutas_exclusivas_del_dueno(): void
    {
        Route::middleware(['auth', 'permiso:usuarios.gestionar'])
            ->get('/_pruebas/usuarios', fn () => response('ok'));
        Route::middleware(['auth', 'permiso:productos.crear'])
            ->get('/_pruebas/crear-producto', fn () => response('ok'));
        Route::middleware(['auth', 'permiso:ventas.realizar'])
            ->get('/_pruebas/pos', fn () => response('ok'));

        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)->get('/_pruebas/usuarios')->assertForbidden();
        $this->actingAs($cajero)->get('/_pruebas/crear-producto')->assertForbidden();
        $this->actingAs($cajero)->get('/_pruebas/pos')->assertOk();

        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)->get('/_pruebas/usuarios')->assertOk();
        $this->actingAs($dueno)->get('/_pruebas/crear-producto')->assertOk();
    }

    public function test_seeder_crea_roles_permisos_y_usuarios_de_desarrollo(): void
    {
        $this->seed();

        $this->assertDatabaseHas('roles', ['nombre' => Rol::DUENO]);
        $this->assertDatabaseHas('roles', ['nombre' => Rol::CAJERO]);

        $dueno = Usuario::where('email', env('SEED_ADMIN_EMAIL', 'admin@verduleria.local'))->first();
        $this->assertNotNull($dueno);
        $this->assertTrue($dueno->esDueno());
        $this->assertSame($dueno->rol->nombre, Rol::DUENO);

        foreach (Permisos::todos() as $permiso) {
            $this->assertTrue($dueno->tienePermiso($permiso));
        }

        $cajero = Usuario::where('email', env('SEED_CAJERO_EMAIL', 'cajero@verduleria.local'))->first();
        $this->assertNotNull($cajero);
        $this->assertSame($cajero->rol->nombre, Rol::CAJERO);

        $this->assertTrue($cajero->tienePermiso(Permisos::POS_USAR));
        $this->assertTrue($cajero->tienePermiso(Permisos::VENTAS_REALIZAR));
        $this->assertTrue($cajero->tienePermiso(Permisos::VENTAS_VER));
        $this->assertTrue($cajero->tienePermiso(Permisos::TICKETS_IMPRIMIR));
        $this->assertFalse($cajero->tienePermiso(Permisos::PRODUCTOS_CREAR));
        $this->assertFalse($cajero->tienePermiso(Permisos::USUARIOS_GESTIONAR));
    }

    /**
     * @return list<string>
     */
    private function permisosPos(): array
    {
        return [
            Permisos::POS_USAR,
            Permisos::VENTAS_REALIZAR,
            Permisos::VENTAS_VER,
            Permisos::TICKETS_IMPRIMIR,
        ];
    }

    private function crearUsuarioConRol(string $nombreRol, array $permisos): Usuario
    {
        $rol = Rol::factory()->create(['nombre' => $nombreRol]);

        $rol->permisos()->sync(
            collect($permisos)
                ->unique()
                ->map(fn (string $nombre) => Permiso::firstOrCreate(['nombre' => $nombre]))
                ->pluck('id'),
        );

        return Usuario::factory()->create(['rol_id' => $rol->id]);
    }
}
