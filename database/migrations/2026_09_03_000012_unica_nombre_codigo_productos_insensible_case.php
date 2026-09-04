<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unicidad de nombre y código de producto insensible a mayúsculas y a
     * espacios al inicio/fin.
     *
     * No se borra ni modifica ningún registro: si existen duplicados la
     * migración se detiene con un error que los enumera, para que se resuelvan
     * a mano antes de aplicar el índice único.
     *
     * Para el código se usa NULLIF(BTRIM(...), '') para que los valores vacíos
     * se traten como NULL y no colisionen entre sí (varios productos sin
     * código). Los NULL nunca entran en el índice único.
     */
    public function up(): void
    {
        $this->detectarDuplicados('productos', 'nombre');
        $this->detectarDuplicados('productos', 'codigo');

        // Elimina el UNIQUE simple sobre codigo (es una restricción) para
        // reemplazarlo por un índice funcional insensible a mayúsculas/espacios.
        Schema::table('productos', function (Blueprint $table) {
            $table->dropUnique(['codigo']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX productos_nombre_normalizado_unique '
            .'ON productos (LOWER(BTRIM(nombre)))'
        );

        DB::statement(
            'CREATE UNIQUE INDEX productos_codigo_normalizado_unique '
            .'ON productos (LOWER(NULLIF(BTRIM(codigo), \'\')))'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS productos_nombre_normalizado_unique');
        DB::statement('DROP INDEX IF EXISTS productos_codigo_normalizado_unique');

        Schema::table('productos', function (Blueprint $table) {
            $table->unique('codigo');
        });
    }

    private function detectarDuplicados(string $tabla, string $columna): void
    {
        $expr = strtolower($columna) === 'codigo'
            ? "LOWER(NULLIF(BTRIM({$columna}), ''))"
            : "LOWER(BTRIM({$columna}))";

        $duplicados = DB::table($tabla)
            ->selectRaw("{$expr} as clave, COUNT(*) as total")
            ->groupBy('clave')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicados->isNotEmpty()) {
            $detalle = $duplicados
                ->map(fn ($fila) => "«{$fila->clave}» (x{$fila->total})")
                ->implode(', ');

            throw new RuntimeException(
                "No se puede aplicar unicidad sobre {$tabla}.{$columna}: hay duplicados ({$detalle}). "
                .'Resolvé los duplicados manualmente y volvé a correr la migración.'
            );
        }
    }
};
