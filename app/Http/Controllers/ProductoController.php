<?php

namespace App\Http\Controllers;

use App\Enums\AccionAuditoria;
use App\Http\Requests\ProductoRequest;
use App\Models\Categoria;
use App\Models\Costo;
use App\Models\Precio;
use App\Models\Producto;
use App\Services\AuditoriaService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductoController extends Controller
{
    public function __construct(private AuditoriaService $auditoria) {}

    /**
     * Lista productos con búsqueda y filtros (nombre, código, categoría, estado).
     *
     * La búsqueda por nombre/código queda preparada para reutilizarse en el POS.
     */
    public function index(Request $request): Response
    {
        $filtros = $this->filtros($request);

        $productos = $this->aplicarFiltros(Producto::query()->with([
            'categoria:id,nombre',
            'precioVigente:id,producto_id,monto',
        ]), $filtros)
            ->orderBy('nombre')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Productos/Index', [
            'productos' => $productos,
            'categorias' => Categoria::query()
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
            'filtros' => [
                'q' => $filtros['termino'],
                'categoria_id' => $filtros['categoria_id'],
                'estado' => $filtros['estado'],
            ],
        ]);
    }

    /**
     * Exporta la lista de productos (con los filtros vigentes) a PDF.
     */
    public function exportarPdf(Request $request): \Illuminate\Http\Response
    {
        $filtros = $this->filtros($request);

        $productos = $this->aplicarFiltros(Producto::query()->with([
            'categoria:id,nombre',
            'precioVigente:id,producto_id,monto',
            'costoVigente:id,producto_id,precio',
        ]), $filtros)
            ->orderBy('nombre')
            ->get();

        $usuario = $request->user();

        $pdf = Pdf::loadView('pdf.productos', [
            'comercio' => config('comercio'),
            'fecha' => now()->format('d/m/Y H:i'),
            'generado_por' => $usuario?->name ?: $usuario?->email ?: 'Usuario sin identificar',
            'filtros' => $this->filtrosParaPdf($filtros),
            'cantidad' => $productos->count(),
            'productos' => $productos,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('listado-de-productos.pdf');
    }

    /**
     * Verifica (para UX) si ya existe un producto con el nombre indicado,
     * respetando unicidad case-insensitive y trim. No reemplaza la
     * validación definitiva del backend: solo alimenta la indicación
     * inmediata en el formulario.
     */
    public function verificarNombre(Request $request): JsonResponse
    {
        $nombre = trim((string) $request->query('nombre', ''));
        $excepto = $request->query('excepto')
            ? (int) $request->query('excepto')
            : null;

        $existe = $nombre !== '' && Producto::query()
            ->whereRaw('LOWER(BTRIM(nombre)) = ?', [mb_strtolower($nombre)])
            ->when($excepto !== null, fn ($q) => $q->where('id', '!=', $excepto))
            ->exists();

        return response()->json(['existe' => $existe]);
    }

    /**
     * Extrae los filtros vigentes del listado (búsqueda, categoría, estado).
     *
     * @return array{termino: string, categoria_id: int|null, estado: string}
     */
    private function filtros(Request $request): array
    {
        $termino = trim((string) $request->query('q', ''));

        // El listado envía "categoria_id"; se mantiene "categoria" como alias.
        $categoriaRaw = $request->query('categoria_id') ?? $request->query('categoria');

        return [
            'termino' => $termino,
            'categoria_id' => $categoriaRaw !== null && $categoriaRaw !== ''
                ? (int) $categoriaRaw
                : null,
            'estado' => (string) $request->query('estado', 'todos'),
        ];
    }

    /**
     * Convierte los filtros vigentes a una lista legible de etiquetas para el PDF.
     * Devuelve un array vacío cuando no hay filtros activos (todos los productos).
     *
     * @param  array{termino: string, categoria_id: int|null, estado: string}  $filtros
     * @return list<string>
     */
    private function filtrosParaPdf(array $filtros): array
    {
        $etiquetas = [];

        if ($filtros['termino'] !== '') {
            $etiquetas[] = "Búsqueda: {$filtros['termino']}";
        }

        if ($filtros['categoria_id'] !== null) {
            $categoria = Categoria::find($filtros['categoria_id']);
            $etiquetas[] = 'Categoría: '.($categoria?->nombre ?? '—');
        }

        if ($filtros['estado'] !== 'todos') {
            $etiquetas[] = 'Estado: '.($filtros['estado'] === 'activos' ? 'Activos' : 'Inactivos');
        }

        return $etiquetas;
    }

    private function aplicarFiltros($query, array $filtros)
    {
        return $query
            ->buscar($filtros['termino'])
            ->when($filtros['categoria_id'] !== null, function ($query) use ($filtros) {
                $query->where('categoria_id', $filtros['categoria_id']);
            })
            ->when(in_array($filtros['estado'], ['activos', 'inactivos'], true), function ($query) use ($filtros) {
                $query->where('activo', $filtros['estado'] === 'activos');
            });
    }

    /**
     * Muestra el formulario para crear un producto.
     */
    public function create(): Response
    {
        return Inertia::render('Productos/Crear', [
            'categorias' => Categoria::query()
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
        ]);
    }

    /**
     * Almacena un producto nuevo con su precio/costo inicial y su imagen (opcionales).
     */
    public function store(ProductoRequest $request): RedirectResponse
    {
        $data = $this->datosProducto($request);

        $producto = DB::transaction(function () use ($request, $data): Producto {
            $producto = Producto::create($data);

            $this->auditoria->registrar(
                AccionAuditoria::PRODUCTO_CREADO,
                $request->user(),
                'producto',
                $producto->id,
                "Se creó el producto «{$producto->nombre}».",
                null,
                $this->datosProductoAuditables($producto),
            );

            $this->guardarPrecioInicial($request, $producto);
            $this->guardarCostoInicial($request, $producto);

            return $producto;
        });

        $this->guardarImagenOpcional($request, $producto);

        return redirect()->route('productos.index')
            ->with('success', 'Producto creado correctamente.');
    }

    /**
     * Muestra el formulario para editar un producto.
     */
    public function edit(Producto $producto): Response
    {
        return Inertia::render('Productos/Editar', [
            'producto' => $producto->load([
                'categoria:id,nombre',
                'precioVigente:id,producto_id,monto',
                'costoVigente:id,producto_id,precio',
            ]),
            'categorias' => Categoria::query()
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
        ]);
    }

    /**
     * Actualiza un producto y, si los importes cambian, su precio/costo vigente.
     */
    public function update(ProductoRequest $request, Producto $producto): RedirectResponse
    {
        $data = $this->datosProducto($request);

        $anterior = $this->datosProductoAuditables($producto);

        DB::transaction(function () use ($request, $data, $anterior, $producto): void {
            $producto->update($data);

            $this->actualizarPrecioSiCambio($request, $producto);
            $this->actualizarCostoSiCambio($request, $producto);

            $this->auditoria->registrar(
                AccionAuditoria::PRODUCTO_MODIFICADO,
                $request->user(),
                'producto',
                $producto->id,
                "Se modificó el producto «{$producto->nombre}».",
                $anterior,
                $this->datosProductoAuditables($producto),
            );
        });

        $this->guardarImagenOpcional($request, $producto);

        return redirect()->route('productos.index')
            ->with('success', 'Producto actualizado correctamente.');
    }

    /**
     * Activa o desactiva un producto.
     */
    public function toggleEstado(Producto $producto): RedirectResponse
    {
        $activoPrevio = $producto->activo;
        $producto->update(['activo' => ! $producto->activo]);

        $accion = $producto->activo
            ? AccionAuditoria::PRODUCTO_ACTIVADO
            : AccionAuditoria::PRODUCTO_DESACTIVADO;

        $this->auditoria->registrar(
            $accion,
            auth()->user(),
            'producto',
            $producto->id,
            ($producto->activo ? 'Se activó' : 'Se desactivó')." el producto «{$producto->nombre}».",
            ['activo' => $activoPrevio],
            ['activo' => $producto->activo],
        );

        return redirect()->back()
            ->with('success', 'Estado del producto actualizado correctamente.');
    }

    /**
     * Purga los campos de precio/imagen antes de asignar los atributos del producto.
     *
     * @return array<string, mixed>
     */
    private function datosProducto(ProductoRequest $request): array
    {
        $data = $request->validated();

        unset($data['monto'], $data['costo'], $data['imagen'], $data['quitar_imagen']);

        return $data;
    }

    /**
     * Crea la primera fila de precio vigente del producto al crearlo.
     *
     * Se registra PRECIO_CREADO (no PRECIO_MODIFICADO).
     */
    private function guardarPrecioInicial(ProductoRequest $request, Producto $producto): void
    {
        $monto = $request->validated('monto');

        if ($monto !== null && $monto !== '') {
            $precio = $producto->precios()->create([
                'monto' => $monto,
                'vigente' => true,
                'usuario_id' => $request->user()->id,
            ]);

            $this->auditoria->registrar(
                AccionAuditoria::PRECIO_CREADO,
                $request->user(),
                'precio',
                $precio->id,
                "Se creó el primer precio de «{$producto->nombre}»: $".number_format((float) $monto, 2, ',', '.').'.',
                null,
                $this->datosPrecioAuditables($precio, $producto),
            );
        }
    }

    /**
     * Crea la primera fila de costo vigente del producto al crearlo.
     *
     * El costo es opcional; si no se envía no se crea fila y queda NULL.
     */
    private function guardarCostoInicial(ProductoRequest $request, Producto $producto): void
    {
        $costo = $request->validated('costo');

        if ($costo !== null && $costo !== '') {
            $costoFila = $producto->costos()->create([
                'precio' => $costo,
                'vigente' => true,
                'usuario_id' => $request->user()->id,
            ]);

            $this->auditoria->registrar(
                AccionAuditoria::COSTO_CREADO,
                $request->user(),
                'costo',
                $costoFila->id,
                "Se asignó el costo inicial de «{$producto->nombre}»: $".number_format((float) $costo, 2, ',', '.').'.',
                null,
                $this->datosCostoAuditables($costoFila, $producto),
            );
        }
    }

    /**
     * Actualiza el precio vigente solo si el importe enviado difiere del actual.
     *
     * El precio anterior pasa a histórico y las ventas ya registradas no se alteran.
     * Asignar el primer precio (si no existe aún) se registra como PRECIO_CREADO.
     */
    private function actualizarPrecioSiCambio(ProductoRequest $request, Producto $producto): void
    {
        $monto = $request->validated('monto');

        if ($monto === null || $monto === '') {
            return;
        }

        $vigente = $producto->precios()->where('vigente', true)->first();

        if ($vigente === null) {
            $precio = $producto->precios()->create([
                'monto' => $monto,
                'vigente' => true,
                'usuario_id' => $request->user()->id,
            ]);

            $this->auditoria->registrar(
                AccionAuditoria::PRECIO_CREADO,
                $request->user(),
                'precio',
                $precio->id,
                "Se asignó el primer precio de «{$producto->nombre}».",
                null,
                $this->datosPrecioAuditables($precio, $producto),
            );

            return;
        }

        $montoNuevo = number_format((float) $monto, 2, '.', '');

        if ((string) $vigente->monto === $montoNuevo) {
            return;
        }

        $montoAnterior = (string) $vigente->monto;

        $vigente->update(['vigente' => false]);

        $precio = $producto->precios()->create([
            'monto' => $monto,
            'vigente' => true,
            'usuario_id' => $request->user()->id,
        ]);

        $this->auditoria->registrar(
            AccionAuditoria::PRECIO_MODIFICADO,
            $request->user(),
            'precio',
            $precio->id,
            "Se modificó el precio de «{$producto->nombre}» de \${$montoAnterior} a \${$montoNuevo}.",
            [
                'producto_id' => $producto->id,
                'producto' => $producto->nombre,
                'precio_anterior' => (float) $montoAnterior,
            ],
            $this->datosPrecioAuditables($precio, $producto),
        );
    }

    /**
     * Actualiza el costo vigente solo si el importe enviado difiere del actual.
     *
     * El costo anterior pasa a histórico y las ventas ya registradas no se alteran.
     * Si el producto aún no tiene costo, asignarlo se registra como COSTO_CREADO.
     */
    private function actualizarCostoSiCambio(ProductoRequest $request, Producto $producto): void
    {
        $costo = $request->validated('costo');

        if ($costo === null || $costo === '') {
            return;
        }

        $vigente = $producto->costos()->where('vigente', true)->first();

        if ($vigente === null) {
            $costoFila = $producto->costos()->create([
                'precio' => $costo,
                'vigente' => true,
                'usuario_id' => $request->user()->id,
            ]);

            $this->auditoria->registrar(
                AccionAuditoria::COSTO_CREADO,
                $request->user(),
                'costo',
                $costoFila->id,
                "Se asignó el primer costo de «{$producto->nombre}».",
                null,
                $this->datosCostoAuditables($costoFila, $producto),
            );

            return;
        }

        $costoNuevo = number_format((float) $costo, 2, '.', '');

        if ((string) $vigente->precio === $costoNuevo) {
            return;
        }

        $costoAnterior = (string) $vigente->precio;

        $vigente->update(['vigente' => false]);

        $costoFila = $producto->costos()->create([
            'precio' => $costo,
            'vigente' => true,
            'usuario_id' => $request->user()->id,
        ]);

        $this->auditoria->registrar(
            AccionAuditoria::COSTO_MODIFICADO,
            $request->user(),
            'costo',
            $costoFila->id,
            "Se modificó el costo de «{$producto->nombre}» de \${$costoAnterior} a \${$costoNuevo}.",
            [
                'producto_id' => $producto->id,
                'producto' => $producto->nombre,
                'costo_anterior' => (float) $costoAnterior,
            ],
            $this->datosCostoAuditables($costoFila, $producto),
        );
    }

    /**
     * Guarda, reemplaza o elimina la imagen del producto en el disco público.
     */
    private function guardarImagenOpcional(Request $request, Producto $producto): void
    {
        if ($request->hasFile('imagen')) {
            $this->borrarImagenSiExiste($producto);

            $producto->update([
                'imagen' => $request->file('imagen')->store('imagenes-productos', 'public'),
            ]);

            return;
        }

        if ($request->boolean('quitar_imagen')) {
            $this->borrarImagenSiExiste($producto);

            $producto->update(['imagen' => null]);
        }
    }

    private function borrarImagenSiExiste(Producto $producto): void
    {
        if ($producto->imagen) {
            Storage::disk('public')->delete($producto->imagen);
        }
    }

    /**
     * Datos relevantes de un producto para la auditoría.
     *
     * La imagen se guarda por su ruta/identificador (nunca el binario).
     * El precio/costo se auditan por separado, por lo que no se incluyen aquí.
     *
     * @return array<string, mixed>
     */
    private function datosProductoAuditables(Producto $producto): array
    {
        return [
            'nombre' => $producto->nombre,
            'categoria_id' => $producto->categoria_id,
            'unidad_medida' => $producto->unidad_medida->value,
            'codigo' => $producto->codigo,
            'descripcion' => $producto->descripcion,
            'imagen' => $producto->imagen,
            'activo' => (bool) $producto->activo,
        ];
    }

    /**
     * Datos relevantes de un precio para la auditoría.
     *
     * @return array<string, mixed>
     */
    private function datosPrecioAuditables(Precio $precio, Producto $producto): array
    {
        return [
            'producto_id' => $producto->id,
            'producto' => $producto->nombre,
            'precio_nuevo' => (float) $precio->monto,
            'vigente' => (bool) $precio->vigente,
        ];
    }

    /**
     * Datos relevantes de un costo para la auditoría.
     *
     * @return array<string, mixed>
     */
    private function datosCostoAuditables(Costo $costo, Producto $producto): array
    {
        return [
            'producto_id' => $producto->id,
            'producto' => $producto->nombre,
            'costo_nuevo' => (float) $costo->precio,
            'vigente' => (bool) $costo->vigente,
        ];
    }
}
