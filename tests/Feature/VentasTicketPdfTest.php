<?php

namespace Tests\Feature;

use App\Enums\AccionAuditoria;
use App\Enums\EstadoPagoVenta;
use App\Enums\MedioPago;
use App\Models\Auditoria;
use App\Models\Caja;
use App\Models\CajaFisica;
use App\Models\Categoria;
use App\Models\Permiso;
use App\Models\Precio;
use App\Models\Producto;
use App\Models\Rol;
use App\Models\Ticket;
use App\Models\TrabajoImpresion;
use App\Models\Usuario;
use App\Models\Venta;
use App\Support\Permisos as PermisosDisponibles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Descarga del ticket de una venta en PDF (térmico 80 mm) desde el detalle.
 */
class VentasTicketPdfTest extends TestCase
{
    use RefreshDatabase;

    private const ANCHO_80MM_MIN = 226.0;

    private const ANCHO_80MM_MAX = 228.0;

    // ------------------------------------------------------------------ Descarga

    public function test_descarga_un_pdf_valido_de_80mm_con_el_snapshot(): void
    {
        $cajero = $this->conectarCajero();
        $producto = $this->producto('Banana', 'KILOGRAMO', 2500);
        $this->abrirCaja($cajero);
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]]);
        $venta = Venta::firstOrFail();

        $respuesta = $this->descargar($cajero, $venta);

        // PDF válido y adjunto.
        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $contenido = $this->contenidoPdf($respuesta);
        $this->assertStringStartsWith('%PDF', $contenido);
        $this->assertMatchesRegularExpression(
            '/attachment; filename="?ticket-\d{6}\.pdf"?/',
            $respuesta->headers->get('Content-Disposition'),
        );

        // Ancho real de 80 mm (punts), no A4.
        [$ancho, $alto] = $this->dimensionesPagina($contenido);
        $this->assertGreaterThanOrEqual(self::ANCHO_80MM_MIN, $ancho);
        $this->assertLessThanOrEqual(self::ANCHO_80MM_MAX, $ancho);
        $this->assertLessThan(500, $ancho, 'No debe ser A4 (595 pt).');

        // El contenido proviene del snapshot del ticket.
        $texto = $this->textoPdf($contenido);
        $this->assertStringContainsString('Banana', $texto);
        $this->assertStringContainsString('5.000,00', $texto);
        $this->assertGreaterThan(0, $alto, 'El alto debe ser > 0.');
    }

    public function test_el_pdf_usa_el_snapshot_inmutable_y_no_precios_actuales(): void
    {
        $cajero = $this->conectarCajero();
        $producto = $this->producto('Banana', 'KILOGRAMO', 2500);
        $this->abrirCaja($cajero);
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 2]]);
        $venta = Venta::firstOrFail();

        // Sube el precio actual después de la venta.
        $this->cambiarPrecio($producto, 99999);

        $texto = $this->textoPdf($this->contenidoPdf($this->descargar($cajero, $venta)));

        // El ticket conserva precio/subtotal originales del snapshot.
        $this->assertStringContainsString('Banana', $texto);
        $this->assertStringContainsString('5.000,00', $texto);
        $this->assertStringNotContainsString('199.998,00', $texto);
    }

    public function test_la_descarga_no_genera_efectos_secundarios(): void
    {
        $cajero = $this->conectarCajero();
        $producto = $this->producto('Banana', 'KILOGRAMO', 2500);
        $this->abrirCaja($cajero);
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $venta = Venta::firstOrFail();

        $trabajosAntes = TrabajoImpresion::count();
        $ticketsAntes = Ticket::count();
        $reimpresionesAntes = Auditoria::where('accion', AccionAuditoria::TICKET_REIMPRESO->value)->count();
        $totalAntes = (float) $venta->total;

        $this->descargar($cajero, $venta)->assertOk();

        // No se crea reimpresión, trabajo de impresión, ticket ni auditoría.
        $this->assertSame($trabajosAntes, TrabajoImpresion::count());
        $this->assertSame($ticketsAntes, Ticket::count());
        $this->assertSame($reimpresionesAntes, Auditoria::where('accion', AccionAuditoria::TICKET_REIMPRESO->value)->count());
        $this->assertSame($totalAntes, (float) $venta->refresh()->total);
    }

    public function test_el_alto_del_pdf_crece_con_el_numero_de_productos(): void
    {
        $cajero = $this->conectarCajero();
        $banana = $this->producto('Banana', 'KILOGRAMO', 2500);
        $bolsa = $this->producto('Bolsa Sopa', 'BOLSA', 4000);
        $naranja = $this->producto('Naranja', 'KILOGRAMO', 1500);
        $this->abrirCaja($cajero);

        $this->vender($cajero, [['producto_id' => $banana->id, 'cantidad' => 1]]);
        $ventaCorta = Venta::orderBy('id')->firstOrFail();
        $altoCorto = $this->alto($this->contenidoPdf($this->descargar($cajero, $ventaCorta)));

        $this->vender($cajero, [
            ['producto_id' => $banana->id, 'cantidad' => 1],
            ['producto_id' => $bolsa->id, 'cantidad' => 1],
            ['producto_id' => $naranja->id, 'cantidad' => 1],
        ]);
        $ventaLarga = Venta::orderByDesc('id')->firstOrFail();
        $altoLargo = $this->alto($this->contenidoPdf($this->descargar($cajero, $ventaLarga)));

        // El ticket con más productos ocupa más alto.
        $this->assertGreaterThan($altoCorto, $altoLargo);

        // El ticket corto no genera una hoja innecesariamente grande.
        $this->assertLessThan(400, $altoCorto, 'El alto no debe ser de hoja A4.');
    }

    // ------------------------------------------------------------------ Protección y casos límite

    public function test_sin_ticket_devuelve_404(): void
    {
        $cajero = $this->crearUsuarioConRol(
            [PermisosDisponibles::VENTAS_VER, PermisosDisponibles::CAJAS_USAR, PermisosDisponibles::POS_USAR, PermisosDisponibles::VENTAS_REALIZAR],
        );
        $cajaFisica = CajaFisica::factory()->create();
        $this->actingAs($cajero)
            ->post('/caja/abrir', ['monto_inicial' => 1000, 'caja_fisica_id' => $cajaFisica->id])
            ->assertRedirect();
        $caja = Caja::firstOrFail();

        $ventaSinTicket = Venta::create([
            'usuario_id' => $cajero->id,
            'caja_id' => $caja->id,
            'medio_pago' => MedioPago::EFECTIVO,
            'total' => 100,
            'estado_pago' => EstadoPagoVenta::PAGADA,
        ]);

        $this->actingAs($cajero)
            ->get(route('ventas.ticket-pdf', $ventaSinTicket))
            ->assertNotFound();
    }

    public function test_sin_permiso_devuelve_403(): void
    {
        $cajero = $this->conectarCajero();
        $producto = $this->producto('Banana', 'KILOGRAMO', 2500);
        $this->abrirCaja($cajero);
        $this->vender($cajero, [['producto_id' => $producto->id, 'cantidad' => 1]]);
        $venta = Venta::firstOrFail();

        $sinPermiso = $this->crearUsuarioConRol([PermisosDisponibles::CAJAS_USAR]);

        $this->actingAs($sinPermiso)
            ->get(route('ventas.ticket-pdf', $venta))
            ->assertForbidden();
    }

    // ------------------------------------------------------------------ Helpers

    private function descargar(Usuario $usuario, Venta $venta): TestResponse
    {
        return $this->actingAs($usuario)
            ->get(route('ventas.ticket-pdf', $venta));
    }

    private function contenidoPdf(TestResponse $respuesta): string
    {
        return $respuesta->getContent();
    }

    private function conectarCajero(): Usuario
    {
        return $this->crearUsuarioConRol([
            PermisosDisponibles::VENTAS_VER,
            PermisosDisponibles::CAJAS_USAR,
            PermisosDisponibles::POS_USAR,
            PermisosDisponibles::VENTAS_REALIZAR,
        ]);
    }

    private function abrirCaja(Usuario $cajero): void
    {
        $cajaFisica = CajaFisica::factory()->create();
        $this->actingAs($cajero)
            ->post('/caja/abrir', ['monto_inicial' => 1000, 'caja_fisica_id' => $cajaFisica->id])
            ->assertRedirect();
    }

    /**
     * @param  list<array{producto_id: int, cantidad: float}>  $items
     */
    private function vender(Usuario $cajero, array $items): void
    {
        $this->actingAs($cajero)
            ->post('/pos/ventas', [
                'medio_pago' => 'EFECTIVO',
                'items' => $items,
                'efectivo_recibido' => $this->totalDeItems($items),
            ])
            ->assertRedirect();
    }

    private function producto(string $nombre, string $unidad, float $monto): Producto
    {
        $categoria = Categoria::firstOrCreate(['nombre' => 'Frutas'], ['activa' => true]);
        $producto = Producto::create([
            'categoria_id' => $categoria->id,
            'nombre' => $nombre,
            'unidad_medida' => $unidad,
            'activo' => true,
        ]);
        Precio::create(['producto_id' => $producto->id, 'monto' => $monto, 'vigente' => true, 'usuario_id' => null]);

        return $producto;
    }

    private function cambiarPrecio(Producto $producto, float $monto): void
    {
        Precio::where('producto_id', $producto->id)->where('vigente', true)->update(['vigente' => false]);
        Precio::create(['producto_id' => $producto->id, 'monto' => $monto, 'vigente' => true, 'usuario_id' => null]);
    }

    /**
     * @param  list<string>  $permisos
     */
    private function crearUsuarioConRol(array $permisos): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => 'CAJERO']);

        $rol->permisos()->sync(
            collect($permisos)
                ->unique()
                ->map(fn (string $nombre) => Permiso::firstOrCreate(['nombre' => $nombre]))
                ->pluck('id'),
        );

        return Usuario::factory()->create(['rol_id' => $rol->id]);
    }

    /**
     * Extrae el ancho y alto de la primera página (MediaBox) en puntos.
     *
     * @return array{0: float, 1: float}
     */
    private function dimensionesPagina(string $pdf): array
    {
        preg_match('/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]/', $pdf, $m);

        return [(float) ($m[3] ?? 0), (float) ($m[4] ?? 0)];
    }

    private function alto(string $pdf): float
    {
        [, $alto] = $this->dimensionesPagina($pdf);

        return $alto;
    }

    private function textoPdf(string $pdf): string
    {
        $archivo = tempnam(sys_get_temp_dir(), 'ticket');
        file_put_contents($archivo, $pdf);

        $salida = shell_exec('pdftotext '.escapeshellarg($archivo).' - 2>/dev/null');
        @unlink($archivo);

        return (string) $salida;
    }
}
