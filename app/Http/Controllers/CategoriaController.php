<?php

namespace App\Http\Controllers;

use App\Enums\AccionAuditoria;
use App\Http\Requests\CategoriaRequest;
use App\Models\Categoria;
use App\Services\AuditoriaService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoriaController extends Controller
{
    public function __construct(private AuditoriaService $auditoria) {}

    /**
     * Lista las categorías del catálogo.
     */
    public function index(): Response
    {
        return Inertia::render('Categorias/Index', [
            'categorias' => Categoria::query()
                ->withCount('productos')
                ->orderBy('nombre')
                ->get(),
        ]);
    }

    /**
     * Muestra el formulario para crear una categoría.
     */
    public function create(): Response
    {
        return Inertia::render('Categorias/Crear');
    }

    /**
     * Almacena una categoría nueva.
     */
    public function store(CategoriaRequest $request): RedirectResponse
    {
        $categoria = Categoria::create($request->validated());

        $this->auditoria->registrar(
            AccionAuditoria::CATEGORIA_CREADA,
            $request->user(),
            'categoria',
            $categoria->id,
            "Se creó la categoría «{$categoria->nombre}».",
            null,
            $this->datosCategoriaAuditables($categoria),
        );

        return redirect()->route('categorias.index')
            ->with('success', 'Categoría creada correctamente.');
    }

    /**
     * Muestra el formulario para editar una categoría.
     */
    public function edit(Categoria $categoria): Response
    {
        return Inertia::render('Categorias/Editar', [
            'categoria' => $categoria,
        ]);
    }

    /**
     * Actualiza una categoría.
     */
    public function update(CategoriaRequest $request, Categoria $categoria): RedirectResponse
    {
        $anterior = $this->datosCategoriaAuditables($categoria);

        $categoria->update($request->validated());

        $this->auditoria->registrar(
            AccionAuditoria::CATEGORIA_MODIFICADA,
            $request->user(),
            'categoria',
            $categoria->id,
            "Se modificó la categoría «{$categoria->nombre}».",
            $anterior,
            $this->datosCategoriaAuditables($categoria),
        );

        return redirect()->route('categorias.index')
            ->with('success', 'Categoría actualizada correctamente.');
    }

    /**
     * Activa o desactiva una categoría.
     */
    public function toggleEstado(Categoria $categoria): RedirectResponse
    {
        $activaPrevia = $categoria->activa;
        $categoria->update(['activa' => ! $categoria->activa]);

        $accion = $categoria->activa
            ? AccionAuditoria::CATEGORIA_ACTIVADA
            : AccionAuditoria::CATEGORIA_DESACTIVADA;

        $this->auditoria->registrar(
            $accion,
            auth()->user(),
            'categoria',
            $categoria->id,
            ($categoria->activa ? 'Se activó' : 'Se desactivó')." la categoría «{$categoria->nombre}».",
            ['activa' => $activaPrevia],
            ['activa' => $categoria->activa],
        );

        return redirect()->route('categorias.index')
            ->with('success', 'Estado de la categoría actualizado correctamente.');
    }

    /**
     * Datos relevantes de una categoría para la auditoría.
     *
     * @return array<string, mixed>
     */
    private function datosCategoriaAuditables(Categoria $categoria): array
    {
        return [
            'nombre' => $categoria->nombre,
            'descripcion' => $categoria->descripcion,
            'activa' => (bool) $categoria->activa,
        ];
    }
}
