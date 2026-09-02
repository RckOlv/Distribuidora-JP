<?php

namespace Tests\Feature;

use App\Models\Permiso;
use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GestionUsuariosTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Autorización

    public function test_dueno_puede_acceder_al_listado_de_usuarios(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/usuarios')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Usuarios/Index'));
    }

    public function test_cajero_no_puede_acceder_al_listado(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());

        $this->actingAs($cajero)
            ->get('/usuarios')
            ->assertForbidden();
    }

    public function test_cajero_no_puede_crear_usuarios(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $rolCajero = $this->crearRol(Rol::CAJERO);

        $this->actingAs($cajero)
            ->post('/usuarios', [
                'name' => 'Nuevo',
                'email' => 'nuevo@test.com',
                'password' => 'secreto123',
                'password_confirmation' => 'secreto123',
                'rol_id' => $rolCajero->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('usuarios', ['email' => 'nuevo@test.com']);
    }

    public function test_cajero_no_puede_editar_usuarios(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $objetivo = Usuario::factory()->create(['rol_id' => $this->crearRol(Rol::CAJERO)->id]);

        $this->actingAs($cajero)
            ->get("/usuarios/{$objetivo->id}/editar")
            ->assertForbidden();

        $this->actingAs($cajero)
            ->put("/usuarios/{$objetivo->id}", [
                'name' => 'Modificado',
                'email' => $objetivo->email,
                'rol_id' => $objetivo->rol_id,
            ])
            ->assertForbidden();

        $this->assertNotSame('Modificado', $objetivo->refresh()->name);
    }

    public function test_cajero_no_puede_activar_o_desactivar_usuarios(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $objetivo = Usuario::factory()->create([
            'rol_id' => $this->crearRol(Rol::CAJERO)->id,
            'activo' => true,
        ]);

        $this->actingAs($cajero)
            ->post("/usuarios/{$objetivo->id}/estado")
            ->assertForbidden();

        $this->assertTrue($objetivo->refresh()->activo);
    }

    // ------------------------------------------------------------------ CRUD

    public function test_dueno_puede_crear_un_cajero(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rolCajero = $this->crearRol(Rol::CAJERO);

        $this->actingAs($dueno)
            ->post('/usuarios', [
                'name' => 'Ana Pérez',
                'email' => 'ana@test.com',
                'password' => 'secreto123',
                'password_confirmation' => 'secreto123',
                'rol_id' => $rolCajero->id,
                'activo' => true,
            ])
            ->assertRedirect(route('usuarios.index'));

        $this->assertDatabaseHas('usuarios', [
            'email' => 'ana@test.com',
            'rol_id' => $rolCajero->id,
        ]);

        // El cajero recién creado queda activo por defecto.
        $creado = Usuario::where('email', 'ana@test.com')->first();
        $this->assertTrue($creado->activo);
        $this->assertTrue(Hash::check('secreto123', $creado->password));
    }

    public function test_dueno_puede_crear_otro_dueno(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rolDueno = $this->crearRol(Rol::DUENO);

        $this->actingAs($dueno)
            ->post('/usuarios', [
                'name' => 'Socio',
                'email' => 'socio@test.com',
                'password' => 'secreto123',
                'password_confirmation' => 'secreto123',
                'rol_id' => $rolDueno->id,
            ])
            ->assertRedirect(route('usuarios.index'));

        $this->assertDatabaseHas('usuarios', ['email' => 'socio@test.com', 'rol_id' => $rolDueno->id]);
    }

    public function test_email_duplicado_es_rechazado(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rolCajero = $this->crearRol(Rol::CAJERO);

        Usuario::factory()->create(['email' => 'repetido@test.com', 'rol_id' => $rolCajero->id]);

        $this->actingAs($dueno)
            ->post('/usuarios', [
                'name' => 'Pretendiente',
                'email' => 'repetido@test.com',
                'password' => 'secreto123',
                'password_confirmation' => 'secreto123',
                'rol_id' => $rolCajero->id,
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_datos_invalidos_no_crean_usuario(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rolCajero = $this->crearRol(Rol::CAJERO);

        $this->actingAs($dueno)
            ->post('/usuarios', [
                'name' => '',
                'email' => 'no-es-email',
                'password' => 'corta',
                'password_confirmation' => 'distinta',
                'rol_id' => 99999,
            ])
            ->assertSessionHasErrors(['name', 'email', 'password', 'rol_id']);

        $this->assertDatabaseCount('usuarios', 1);
    }

    public function test_el_usuario_creado_tiene_la_contrasena_hasheada(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rolCajero = $this->crearRol(Rol::CAJERO);

        $this->actingAs($dueno)
            ->post('/usuarios', [
                'name' => 'Clave',
                'email' => 'clave@test.com',
                'password' => 'secreto123',
                'password_confirmation' => 'secreto123',
                'rol_id' => $rolCajero->id,
            ]);

        $creado = Usuario::where('email', 'clave@test.com')->first();
        $this->assertNotSame('secreto123', $creado->password);
        $this->assertTrue(Hash::check('secreto123', $creado->password));
    }

    public function test_dueno_puede_editar_un_usuario(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rolCajero = $this->crearRol(Rol::CAJERO);
        $objetivo = Usuario::factory()->create([
            'name' => 'Original',
            'email' => 'obj@test.com',
            'rol_id' => $rolCajero->id,
        ]);

        $this->actingAs($dueno)
            ->put("/usuarios/{$objetivo->id}", [
                'name' => 'Actualizado',
                'email' => 'obj@test.com',
                'rol_id' => $rolCajero->id,
            ])
            ->assertRedirect(route('usuarios.index'));

        $this->assertSame('Actualizado', $objetivo->refresh()->name);
    }

    public function test_cambiar_contrasena_al_editar_funciona(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rolCajero = $this->crearRol(Rol::CAJERO);
        $objetivo = Usuario::factory()->create([
            'password' => Hash::make('original123'),
            'rol_id' => $rolCajero->id,
        ]);

        $this->actingAs($dueno)
            ->put("/usuarios/{$objetivo->id}", [
                'name' => $objetivo->name,
                'email' => $objetivo->email,
                'rol_id' => $rolCajero->id,
                'password' => 'nueva1234',
                'password_confirmation' => 'nueva1234',
            ])
            ->assertRedirect(route('usuarios.index'));

        $this->assertTrue(Hash::check('nueva1234', $objetivo->refresh()->password));
    }

    public function test_editar_sin_nueva_contrasena_conserva_la_anterior(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rolCajero = $this->crearRol(Rol::CAJERO);
        $originalHash = Hash::make('original123');
        $objetivo = Usuario::factory()->create([
            'password' => $originalHash,
            'rol_id' => $rolCajero->id,
        ]);

        $this->actingAs($dueno)
            ->put("/usuarios/{$objetivo->id}", [
                'name' => 'Otro Nombre',
                'email' => $objetivo->email,
                'rol_id' => $rolCajero->id,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('usuarios.index'));

        $this->assertTrue(Hash::check('original123', $objetivo->refresh()->password));
    }

    public function test_dueno_puede_desactivar_y_activar_un_usuario(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        $rolCajero = $this->crearRol(Rol::CAJERO);
        $objetivo = Usuario::factory()->create([
            'rol_id' => $rolCajero->id,
            'activo' => true,
        ]);

        $this->actingAs($dueno)
            ->post("/usuarios/{$objetivo->id}/estado")
            ->assertRedirect(route('usuarios.index'));

        $this->assertFalse($objetivo->refresh()->activo);

        $this->actingAs($dueno)
            ->post("/usuarios/{$objetivo->id}/estado")
            ->assertRedirect(route('usuarios.index'));

        $this->assertTrue($objetivo->refresh()->activo);
    }

    // ------------------------------------------------------------------ Seguridad

    public function test_cajero_no_puede_escalar_privilegios_manipulando_el_request(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, $this->permisosPos());
        $rolDueno = $this->crearRol(Rol::DUENO);

        // El cajero intenta cambiarse a rol Dueño enviando un request manipuldo.
        $this->actingAs($cajero)
            ->put("/usuarios/{$cajero->id}", [
                'name' => $cajero->name,
                'email' => $cajero->email,
                'rol_id' => $rolDueno->id,
            ])
            ->assertForbidden();

        $this->assertNotSame($rolDueno->id, $cajero->refresh()->rol_id);
    }

    public function test_no_se_puede_dejar_el_sistema_sin_dueno_activo(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);
        // Solo este dueño está activo.

        // No puede desactivarse a sí mismo (quedarían 0 dueños activos).
        $this->actingAs($dueno)
            ->post("/usuarios/{$dueno->id}/estado")
            ->assertRedirect(route('usuarios.index'));

        $this->assertTrue($dueno->refresh()->activo);

        // Tampoco puede cambiarse su propio rol a Cajero (0 dueños activos).
        $rolCajero = $this->crearRol(Rol::CAJERO);
        $this->actingAs($dueno)
            ->put("/usuarios/{$dueno->id}", [
                'name' => $dueno->name,
                'email' => $dueno->email,
                'rol_id' => $rolCajero->id,
            ])
            ->assertStatus(422);

        $this->assertTrue($dueno->refresh()->esDueno());

        // Pero con un segundo dueño activo, sí puede desactivar/despromover.
        $rolDueno = $this->crearRol(Rol::DUENO);
        Usuario::factory()->create(['rol_id' => $rolDueno->id, 'activo' => true]);

        $this->actingAs($dueno)
            ->post("/usuarios/{$dueno->id}/estado")
            ->assertRedirect(route('usuarios.index'));

        $this->assertFalse($dueno->refresh()->activo);
    }

    public function test_no_se_eliminan_fisicamente_usuarios(): void
    {
        $this->seed();

        $dueno = Usuario::where('email', env('SEED_ADMIN_EMAIL', 'admin@verduleria.local'))->first();
        $cajero = Usuario::where('email', env('SEED_CAJERO_EMAIL', 'cajero@verduleria.local'))->first();

        $this->actingAs($dueno)
            ->post("/usuarios/{$cajero->id}/estado")
            ->assertRedirect(route('usuarios.index'));

        // Se desactivó, pero el registro sigue existiendo en la base.
        $this->assertDatabaseHas('usuarios', ['id' => $cajero->id]);
        $this->assertFalse($cajero->refresh()->activo);
    }

    // ------------------------------------------------------------------ Helpers

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

    /**
     * @param  list<string>  $permisos
     */
    private function crearUsuarioConRol(string $nombreRol, array $permisos): Usuario
    {
        $rol = $this->crearRol($nombreRol);

        $rol->permisos()->sync(
            collect($permisos)
                ->unique()
                ->map(fn (string $nombre) => Permiso::firstOrCreate(['nombre' => $nombre]))
                ->pluck('id'),
        );

        return Usuario::factory()->create(['rol_id' => $rol->id]);
    }

    private function crearRol(string $nombre): Rol
    {
        return Rol::firstOrCreate(['nombre' => $nombre]);
    }
}
