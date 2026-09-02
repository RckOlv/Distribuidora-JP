<?php

namespace App\Providers;

use App\Models\Usuario;
use App\Support\Permisos;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::before(function (Usuario $usuario): ?bool {
            return $usuario->esDueno() ? true : null;
        });

        foreach (Permisos::todos() as $permiso) {
            Gate::define($permiso, function (Usuario $usuario) use ($permiso): bool {
                return $usuario->tienePermiso($permiso);
            });
        }
    }
}
