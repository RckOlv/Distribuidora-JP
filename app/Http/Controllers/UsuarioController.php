<?php

namespace App\Http\Controllers;

use App\Enums\AccionAuditoria;
use App\Http\Requests\UsuarioRequest;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\AuditoriaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class UsuarioController extends Controller
{
    public function __construct(private AuditoriaService $auditoria) {}

    /**
     * Lista los usuarios del sistema con su rol y estado.
     */
    public function index(): Response
    {
        return Inertia::render('Usuarios/Index', [
            'usuarios' => Usuario::query()
                ->with('rol:id,nombre')
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Formulario para registrar un nuevo usuario.
     */
    public function create(): Response
    {
        return Inertia::render('Usuarios/Crear', [
            'roles' => $this->rolesDisponibles(),
        ]);
    }

    /**
     * Almacena un usuario nuevo. La contraseña la hashea el cast 'hashed'.
     */
    public function store(UsuarioRequest $request): RedirectResponse
    {
        $datos = $request->validated();

        $usuario = Usuario::create([
            'name' => $datos['name'],
            'email' => $datos['email'],
            'password' => $datos['password'],
            'rol_id' => $datos['rol_id'],
            'activo' => $datos['activo'] ?? true,
        ]);

        $this->auditoria->registrar(
            AccionAuditoria::USUARIO_CREADO,
            $request->user(),
            'usuario',
            $usuario->id,
            "Se creó el usuario «{$usuario->name}».",
            null,
            $this->datosUsuarioAuditables($usuario),
        );

        return redirect()->route('usuarios.index')
            ->with('success', 'Usuario creado correctamente.');
    }

    /**
     * Formulario para editar un usuario.
     */
    public function edit(Usuario $usuario): Response
    {
        $usuario->load('rol:id,nombre');

        return Inertia::render('Usuarios/Editar', [
            'usuario' => $usuario,
            'roles' => $this->rolesDisponibles(),
        ]);
    }

    /**
     * Actualiza un usuario. Solo cambia la contraseña si se informa una nueva.
     */
    public function update(UsuarioRequest $request, Usuario $usuario): RedirectResponse
    {
        $datos = $request->validated();

        // Protección: no dejar el sistema sin un dueño activo al cambiar el rol.
        $this->asegurarQueHayaDuenoActivo($usuario, $datos['rol_id']);

        $anterior = $this->datosUsuarioAuditables($usuario);

        $actualizar = [
            'name' => $datos['name'],
            'email' => $datos['email'],
            'rol_id' => $datos['rol_id'],
            'activo' => $datos['activo'] ?? true,
        ];

        // Contraseña opcional: solo se toca si se envía una nueva.
        $cambioContrasena = false;
        if (! empty($datos['password'])) {
            $actualizar['password'] = $datos['password'];
            $cambioContrasena = true;
        }

        $usuario->update($actualizar);

        $nuevo = $this->datosUsuarioAuditables($usuario);

        if ($cambioContrasena) {
            $nuevo['password_cambiada'] = true;
        }

        $this->auditoria->registrar(
            AccionAuditoria::USUARIO_MODIFICADO,
            $request->user(),
            'usuario',
            $usuario->id,
            "Se modificó el usuario «{$usuario->name}».",
            $anterior,
            $nuevo,
        );

        return redirect()->route('usuarios.index')
            ->with('success', 'Usuario actualizado correctamente.');
    }

    /**
     * Activa o desactiva un usuario.
     */
    public function toggleEstado(Usuario $usuario): RedirectResponse
    {
        // Protección: no desactivar un dueño si quedará el sistema sin dueños activos.
        if ($usuario->activo && $this->esUltimoDuenoActivo($usuario)) {
            return redirect()->route('usuarios.index')
                ->with('success', 'No se puede desactivar: debe haber al menos un dueño activo.');
        }

        $activoPrevio = $usuario->activo;
        $usuario->update(['activo' => ! $usuario->activo]);

        $accion = $usuario->activo
            ? AccionAuditoria::USUARIO_ACTIVADO
            : AccionAuditoria::USUARIO_DESACTIVADO;

        $this->auditoria->registrar(
            $accion,
            auth()->user(),
            'usuario',
            $usuario->id,
            ($usuario->activo ? 'Se activó' : 'Se desactivó')." el usuario «{$usuario->name}».",
            ['activo' => $activoPrevio],
            ['activo' => $usuario->activo],
        );

        return redirect()->route('usuarios.index')
            ->with('success', 'Estado del usuario actualizado correctamente.');
    }

    /**
     * Impide que el último dueño activo deje de ser dueño activo (desactivando
     * o cambiando su rol desde la edición).
     */
    private function asegurarQueHayaDuenoActivo(Usuario $usuario, int $nuevoRolId): void
    {
        if ($usuario->activo && $usuario->rol_id !== $nuevoRolId && $this->esUltimoDuenoActivo($usuario)) {
            abort(422, 'No se puede cambiar el rol: debe haber al menos un dueño activo.');
        }
    }

    /**
     * Determina si este usuario es el único dueño activo en el sistema.
     */
    private function esUltimoDuenoActivo(Usuario $usuario): bool
    {
        $duenoRolId = Rol::where('nombre', Rol::DUENO)->value('id');

        $dueñosActivos = Usuario::query()
            ->where('rol_id', $duenoRolId)
            ->where('activo', true)
            ->count();

        return $usuario->rol_id === $duenoRolId
            && $dueñosActivos <= 1;
    }

    /**
     * Roles entre los que se puede elegir al gestionar usuarios.
     *
     * @return Collection<int, Rol>
     */
    private function rolesDisponibles()
    {
        return Rol::query()
            ->whereIn('nombre', [Rol::DUENO, Rol::CAJERO])
            ->orderBy('nombre')
            ->get(['id', 'nombre']);
    }

    /**
     * Datos relevantes de un usuario para la auditoría.
     *
     * Nunca incluye la contraseña ni su hash.
     *
     * @return array<string, mixed>
     */
    private function datosUsuarioAuditables(Usuario $usuario): array
    {
        return [
            'name' => $usuario->name,
            'email' => $usuario->email,
            'rol' => $usuario->rol?->nombre,
            'activo' => (bool) $usuario->activo,
        ];
    }
}
