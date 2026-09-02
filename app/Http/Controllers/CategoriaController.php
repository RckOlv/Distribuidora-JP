<?php

namespace App\Http\Controllers;

use App\Http\Requests\CategoriaRequest;
use App\Models\Categoria;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class CategoriaController extends Controller
{
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
        Categoria::create($request->validated());

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
        $categoria->update($request->validated());

        return redirect()->route('categorias.index')
            ->with('success', 'Categoría actualizada correctamente.');
    }

    /**
     * Activa o desactiva una categoría.
     */
    public function toggleEstado(Categoria $categoria): RedirectResponse
    {
        $categoria->update(['activa' => ! $categoria->activa]);

        return redirect()->route('categorias.index')
            ->with('success', 'Estado de la categoría actualizado correctamente.');
    }
}
