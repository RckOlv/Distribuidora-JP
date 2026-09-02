<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductoRequest;
use App\Models\Categoria;
use App\Models\Producto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProductoController extends Controller
{
    /**
     * Lista productos con búsqueda y filtros (nombre, código, categoría, estado).
     *
     * La búsqueda por nombre/código queda preparada para reutilizarse en el POS.
     */
    public function index(Request $request): Response
    {
        $termino = trim((string) $request->query('q', ''));
        $categoriaId = $request->query('categoria');
        $estado = (string) $request->query('estado', 'todos');

        $productos = Producto::query()
            ->with(['categoria:id,nombre', 'precioVigente:id,producto_id,monto'])
            ->buscar($termino)
            ->when($categoriaId, fn ($query) => $query->where('categoria_id', $categoriaId))
            ->when(in_array($estado, ['activos', 'inactivos'], true), function ($query) use ($estado) {
                $query->where('activo', $estado === 'activos');
            })
            ->orderBy('nombre')
            ->paginate(10)
            ->withQueryString();

        return Inertia::render('Productos/Index', [
            'productos' => $productos,
            'categorias' => Categoria::query()
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
            'filtros' => [
                'q' => $termino,
                'categoria_id' => $categoriaId ? (int) $categoriaId : null,
                'estado' => $estado,
            ],
        ]);
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
     * Almacena un producto nuevo con su precio inicial y su imagen (opcionales).
     */
    public function store(ProductoRequest $request): RedirectResponse
    {
        $data = $this->datosProducto($request);

        $producto = Producto::create($data);

        $this->guardarPrecioInicial($request, $producto);
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
            'producto' => $producto->load(['categoria:id,nombre', 'precioVigente:id,producto_id,monto']),
            'categorias' => Categoria::query()
                ->orderBy('nombre')
                ->get(['id', 'nombre']),
        ]);
    }

    /**
     * Actualiza un producto y, si el importe cambió, su precio vigente.
     */
    public function update(ProductoRequest $request, Producto $producto): RedirectResponse
    {
        $data = $this->datosProducto($request);

        $producto->update($data);

        $this->actualizarPrecioSiCambio($request, $producto);
        $this->guardarImagenOpcional($request, $producto);

        return redirect()->route('productos.index')
            ->with('success', 'Producto actualizado correctamente.');
    }

    /**
     * Activa o desactiva un producto.
     */
    public function toggleEstado(Producto $producto): RedirectResponse
    {
        $producto->update(['activo' => ! $producto->activo]);

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

        unset($data['monto'], $data['imagen'], $data['quitar_imagen']);

        return $data;
    }

    /**
     * Crea la primera fila de precio vigente del producto.
     */
    private function guardarPrecioInicial(ProductoRequest $request, Producto $producto): void
    {
        $monto = $request->validated('monto');

        if ($monto !== null && $monto !== '') {
            $producto->precios()->create([
                'monto' => $monto,
                'vigente' => true,
                'usuario_id' => $request->user()->id,
            ]);
        }
    }

    /**
     * Actualiza el precio vigente solo si el importe enviado difiere del actual.
     *
     * El precio anterior pasa a histórico y la ventas ya registradas no se alteran.
     */
    private function actualizarPrecioSiCambio(ProductoRequest $request, Producto $producto): void
    {
        $monto = $request->validated('monto');

        if ($monto === null || $monto === '') {
            return;
        }

        $vigente = $producto->precios()->where('vigente', true)->first();

        if ($vigente === null) {
            $producto->precios()->create([
                'monto' => $monto,
                'vigente' => true,
                'usuario_id' => $request->user()->id,
            ]);

            return;
        }

        $montoNuevo = number_format((float) $monto, 2, '.', '');

        if ((string) $vigente->monto === $montoNuevo) {
            return;
        }

        $vigente->update(['vigente' => false]);

        $producto->precios()->create([
            'monto' => $monto,
            'vigente' => true,
            'usuario_id' => $request->user()->id,
        ]);
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
}
