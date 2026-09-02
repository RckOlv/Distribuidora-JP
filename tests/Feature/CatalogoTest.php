<?php

namespace Tests\Feature;

use App\Models\Categoria;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Usuario;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CatalogoTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ Categorías

    public function test_dueno_puede_listar_categorias(): void
    {
        Categoria::create(['nombre' => 'Frutas']);
        Categoria::create(['nombre' => 'Verduras']);

        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/categorias')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Categorias/Index')
                ->has('categorias', 2));
    }

    public function test_dueno_puede_crear_categoria(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/categorias', ['nombre' => 'Cítricos'])
            ->assertRedirect(route('categorias.index'));

        $this->assertDatabaseHas('categorias', ['nombre' => 'Cítricos', 'activa' => true]);
    }

    public function test_nombre_de_categoria_es_obligatorio(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/categorias', ['nombre' => ''])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(0, Categoria::count());
    }

    public function test_nombre_de_categoria_no_puede_duplicarse(): void
    {
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)->post('/categorias', ['nombre' => 'Frutas'])->assertValid();

        $this->actingAs($dueno)
            ->post('/categorias', ['nombre' => 'Frutas'])
            ->assertSessionHasErrors('nombre');

        $this->assertSame(1, Categoria::count());
    }

    public function test_dueno_puede_editar_categoria(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->put("/categorias/{$categoria->id}", ['nombre' => 'Frutas y Verduras'])
            ->assertRedirect(route('categorias.index'));

        $this->assertDatabaseHas('categorias', ['id' => $categoria->id, 'nombre' => 'Frutas y Verduras']);
    }

    public function test_dueno_puede_activar_y_desactivar_categoria(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas', 'activa' => true]);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post("/categorias/{$categoria->id}/estado")
            ->assertRedirect(route('categorias.index'));

        $this->assertDatabaseHas('categorias', ['id' => $categoria->id, 'activa' => false]);

        $this->actingAs($dueno)
            ->post("/categorias/{$categoria->id}/estado")
            ->assertRedirect(route('categorias.index'));

        $this->assertDatabaseHas('categorias', ['id' => $categoria->id, 'activa' => true]);
    }

    public function test_cajero_recibe_403_en_rutas_administrativas_de_categorias(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [
            PermisosDisponibles::VENTAS_REALIZAR,
            PermisosDisponibles::VENTAS_VER,
            PermisosDisponibles::TICKETS_IMPRIMIR,
        ]);
        $categoria = Categoria::create(['nombre' => 'Frutas']);

        $this->actingAs($cajero)->get('/categorias')->assertForbidden();
        $this->actingAs($cajero)->get('/categorias/crear')->assertForbidden();
        $this->actingAs($cajero)->post('/categorias', ['nombre' => 'Nueva'])->assertForbidden();
        $this->actingAs($cajero)->get("/categorias/{$categoria->id}/editar")->assertForbidden();
        $this->actingAs($cajero)->put("/categorias/{$categoria->id}", ['nombre' => 'Editar'])->assertForbidden();
        $this->actingAs($cajero)->post("/categorias/{$categoria->id}/estado")->assertForbidden();

        $this->assertDatabaseMissing('categorias', ['nombre' => 'Nueva']);
    }

    // ------------------------------------------------------------------ Productos

    public function test_dueno_puede_listar_productos(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $this->crearProducto($categoria, 'Banana', 'KILOGRAMO');
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/productos')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Productos/Index')
                ->has('productos.data', 1));
    }

    public function test_dueno_puede_crear_producto_con_codigo(): void
    {
        $categoria = Categoria::create(['nombre' => 'Bolsas']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Bolsa Sopa',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'BOLSA',
                'codigo' => '7791234567',
            ])
            ->assertRedirect(route('productos.index'));

        $this->assertDatabaseHas('productos', [
            'nombre' => 'Bolsa Sopa',
            'categoria_id' => $categoria->id,
            'unidad_medida' => 'BOLSA',
            'codigo' => '7791234567',
        ]);
    }

    public function test_producto_puede_crearse_sin_codigo(): void
    {
        $categoria = Categoria::create(['nombre' => 'Verduras']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Papa',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
            ])
            ->assertRedirect(route('productos.index'));

        $this->assertDatabaseHas('productos', ['nombre' => 'Papa', 'codigo' => null]);
    }

    public function test_codigo_de_producto_no_puede_duplicarse(): void
    {
        $categoria = Categoria::create(['nombre' => 'Bolsas']);
        $this->crearProducto($categoria, 'Bolsa Sopa', 'BOLSA', '7791234567');
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Bolsa Verduras',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'BOLSA',
                'codigo' => '7791234567',
            ])
            ->assertSessionHasErrors('codigo');

        $this->assertSame(1, Producto::count());
    }

    public function test_nombre_de_producto_es_obligatorio(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'UNIDAD',
            ])
            ->assertSessionHasErrors('nombre');
    }

    public function test_tipo_de_venta_solo_acepta_kilogramo_bolsa_o_unidad(): void
    {
        $categoria = Categoria::create(['nombre' => 'Verduras']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Cebolla',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'GRAMA',
            ])
            ->assertSessionHasErrors('unidad_medida');

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Ajo',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILO',
            ])
            ->assertSessionHasErrors('unidad_medida');

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Cebolla',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'BOLSA',
            ])
            ->assertValid();

        $this->assertSame(1, Producto::count());
    }

    public function test_dueno_puede_editar_producto(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $producto = $this->crearProducto($categoria, 'Banana', 'KILOGRAMO');
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->put("/productos/{$producto->id}", [
                'nombre' => 'Banana Ecuatoriana',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
            ])
            ->assertRedirect(route('productos.index'));

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'nombre' => 'Banana Ecuatoriana']);
    }

    public function test_dueno_puede_activar_y_desactivar_producto(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $producto = $this->crearProducto($categoria, 'Banana', 'KILOGRAMO');
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->from(route('productos.index'))
            ->post("/productos/{$producto->id}/estado")
            ->assertRedirect(route('productos.index'));

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'activo' => false]);

        $this->actingAs($dueno)
            ->from(route('productos.index'))
            ->post("/productos/{$producto->id}/estado")
            ->assertRedirect(route('productos.index'));

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'activo' => true]);
    }

    public function test_cajero_recibe_403_en_rutas_administrativas_de_productos(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [
            PermisosDisponibles::VENTAS_REALIZAR,
            PermisosDisponibles::VENTAS_VER,
            PermisosDisponibles::TICKETS_IMPRIMIR,
        ]);
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $producto = $this->crearProducto($categoria, 'Banana', 'KILOGRAMO');

        $this->actingAs($cajero)->get('/productos')->assertForbidden();
        $this->actingAs($cajero)->get('/productos/crear')->assertForbidden();
        $this->actingAs($cajero)->post('/productos', ['nombre' => 'Nuevo', 'categoria_id' => $categoria->id, 'unidad_medida' => 'UNIDAD'])->assertForbidden();
        $this->actingAs($cajero)->get("/productos/{$producto->id}/editar")->assertForbidden();
        $this->actingAs($cajero)->put("/productos/{$producto->id}", ['nombre' => 'Editar', 'categoria_id' => $categoria->id, 'unidad_medida' => 'UNIDAD'])->assertForbidden();
        $this->actingAs($cajero)->post("/productos/{$producto->id}/estado")->assertForbidden();
    }

    public function test_listado_filtra_por_nombre_y_codigo(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $this->crearProducto($categoria, 'Papa', 'KILOGRAMO');
        $this->crearProducto($categoria, 'Manzana', 'KILOGRAMO', '7790000111');
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->get('/productos?q=papa')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Productos/Index')
                ->has('productos.data', 1)
                ->where('productos.data.0.nombre', 'Papa'));

        $this->actingAs($dueno)
            ->get('/productos?q=7790000111')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Productos/Index')
                ->has('productos.data', 1)
                ->where('productos.data.0.nombre', 'Manzana'));
    }

    // ------------------------------------------------------------------ Precios (dentro del flujo de Producto)

    public function test_dueno_establece_precio_al_crear_producto(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Pera',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
                'monto' => 1250,
            ])
            ->assertRedirect(route('productos.index'));

        $producto = Producto::where('nombre', 'Pera')->first();
        $this->assertNotNull($producto);

        $this->assertDatabaseHas('precios', [
            'producto_id' => $producto->id,
            'monto' => 1250,
            'vigente' => true,
            'usuario_id' => $dueno->id,
        ]);
    }

    public function test_dueno_modifica_precio_al_editar_producto_conservando_historial(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $producto = $this->crearProducto($categoria, 'Banana', 'KILOGRAMO');
        Precio::create(['producto_id' => $producto->id, 'monto' => 1800, 'vigente' => true]);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->put("/productos/{$producto->id}", [
                'nombre' => 'Banana',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
                'monto' => 2000,
            ])
            ->assertRedirect(route('productos.index'));

        $this->assertDatabaseHas('precios', [
            'producto_id' => $producto->id,
            'monto' => 1800,
            'vigente' => false,
        ]);

        $this->assertDatabaseHas('precios', [
            'producto_id' => $producto->id,
            'monto' => 2000,
            'vigente' => true,
            'usuario_id' => $dueno->id,
        ]);

        $this->assertSame(
            1,
            Precio::where('producto_id', $producto->id)->where('vigente', true)->count(),
            'Solo debe existir un precio vigente por producto.',
        );

        $this->assertSame(
            2,
            Precio::where('producto_id', $producto->id)->count(),
            'El precio anterior debe conservarse en el historial.',
        );
    }

    public function test_no_cambiar_precio_no_crea_fila_adicional(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $producto = $this->crearProducto($categoria, 'Banana', 'KILOGRAMO');
        Precio::create(['producto_id' => $producto->id, 'monto' => 1800, 'vigente' => true]);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->put("/productos/{$producto->id}", [
                'nombre' => 'Banana Ecuatoriana',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
                'monto' => 1800,
            ])
            ->assertRedirect(route('productos.index'));

        $this->assertDatabaseHas('productos', ['id' => $producto->id, 'nombre' => 'Banana Ecuatoriana']);

        $this->assertSame(
            1,
            Precio::where('producto_id', $producto->id)->count(),
            'Editar sin cambiar el importe no debe crear una fila histórica nueva.',
        );

        $this->assertSame(
            1,
            Precio::where('producto_id', $producto->id)->where('vigente', true)->count(),
            'El precio vigente debe mantenerse.',
        );
    }

    public function test_monto_invalido_es_rechazado(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Pera',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
                'monto' => 0,
            ])
            ->assertSessionHasErrors('monto');

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Manzana',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
                'monto' => 'abc',
            ])
            ->assertSessionHasErrors('monto');

        $this->assertSame(0, Producto::count());
    }

    public function test_cajero_no_puede_modificar_precios_aunque_intente_la_peticion_directa(): void
    {
        $cajero = $this->crearUsuarioConRol(Rol::CAJERO, [
            PermisosDisponibles::VENTAS_REALIZAR,
            PermisosDisponibles::VENTAS_VER,
            PermisosDisponibles::TICKETS_IMPRIMIR,
        ]);
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $producto = $this->crearProducto($categoria, 'Banana', 'KILOGRAMO');
        Precio::create(['producto_id' => $producto->id, 'monto' => 1800, 'vigente' => true]);

        $this->actingAs($cajero)
            ->put("/productos/{$producto->id}", [
                'nombre' => 'Banana',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
                'monto' => 2000,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('precios', ['producto_id' => $producto->id, 'monto' => 1800, 'vigente' => true]);
        $this->assertSame(1, Precio::where('producto_id', $producto->id)->count());
    }

    // ------------------------------------------------------------------ Imágenes

    public function test_producto_puede_crearse_sin_imagen(): void
    {
        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Pera',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
            ])
            ->assertRedirect(route('productos.index'));

        $this->assertDatabaseHas('productos', ['nombre' => 'Pera', 'imagen' => null]);
    }

    public function test_producto_puede_crearse_con_imagen_valida(): void
    {
        Storage::fake('public');

        $categoria = Categoria::create(['nombre' => 'Bolsas']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Bolsa Sopa',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'BOLSA',
                'imagen' => $this->imagenFake('bolsa.png'),
            ])
            ->assertRedirect(route('productos.index'));

        $producto = Producto::where('nombre', 'Bolsa Sopa')->first();
        $this->assertNotNull($producto);
        $this->assertNotNull($producto->imagen);
        Storage::disk('public')->assertExists($producto->imagen);
    }

    public function test_archivo_invalido_es_rechazado(): void
    {
        Storage::fake('public');

        $categoria = Categoria::create(['nombre' => 'Frutas']);
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->post('/productos', [
                'nombre' => 'Pera',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'KILOGRAMO',
                'imagen' => UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('imagen');

        $this->assertSame(0, Producto::count());
    }

    public function test_imagen_puede_reemplazarse(): void
    {
        Storage::fake('public');

        $categoria = Categoria::create(['nombre' => 'Bolsas']);
        $producto = $this->crearProducto($categoria, 'Bolsa Sopa', 'BOLSA');

        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $primera = $this->imagenFake('bolsa1.png');
        $producto->update(['imagen' => $primera->store('imagenes-productos', 'public')]);

        $this->actingAs($dueno)
            ->put("/productos/{$producto->id}", [
                'nombre' => 'Bolsa Sopa',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'BOLSA',
                'imagen' => $this->imagenFake('bolsa2.png'),
            ])
            ->assertRedirect(route('productos.index'));

        $producto->refresh();

        $this->assertNotNull($producto->imagen);
        Storage::disk('public')->assertExists($producto->imagen);
        Storage::disk('public')->assertMissing($primera->hashName() ? 'imagenes-productos/'.$primera->hashName() : null);
    }

    public function test_imagen_puede_eliminarse(): void
    {
        Storage::fake('public');

        $categoria = Categoria::create(['nombre' => 'Bolsas']);
        $producto = $this->crearProducto($categoria, 'Bolsa Sopa', 'BOLSA');
        $archivo = $this->imagenFake('bolsa.png');
        $producto->update(['imagen' => $archivo->store('imagenes-productos', 'public')]);
        $rutaOriginal = $producto->imagen;
        $dueno = $this->crearUsuarioConRol(Rol::DUENO, []);

        $this->actingAs($dueno)
            ->put("/productos/{$producto->id}", [
                'nombre' => 'Bolsa Sopa',
                'categoria_id' => $categoria->id,
                'unidad_medida' => 'BOLSA',
                'quitar_imagen' => '1',
            ])
            ->assertRedirect(route('productos.index'));

        $producto->refresh();

        $this->assertNull($producto->imagen);
        Storage::disk('public')->assertMissing($rutaOriginal);
    }

    // ------------------------------------------------------------------ Helpers

    private function crearProducto(Categoria $categoria, string $nombre, string $unidad, ?string $codigo = null): Producto
    {
        return Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => $nombre,
            'unidad_medida' => $unidad,
            'codigo' => $codigo,
        ]);
    }

    private function imagenFake(string $nombre): UploadedFile
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        );

        return UploadedFile::fake()->createWithContent($nombre, $png);
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
