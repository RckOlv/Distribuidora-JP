<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Crea la identidad persistente de caja física y relaciona cada sesión
     * de caja con su caja física. Reemplaza la restricción global de una
     * única caja ABIERTA por una restricción por caja física.
     */
    public function up(): void
    {
        // 1. Tabla de cajas físicas (puesto físico permanente del negocio).
        Schema::create('cajas_fisicas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        // 2. Relacionar cada sesión de caja con su caja física.
        Schema::table('cajas', function (Blueprint $table) {
            $table->foreignId('caja_fisica_id')
                ->nullable()
                ->constrained('cajas_fisicas')
                ->nullOnDelete()
                ->after('id');
        });

        // 3. Backfill seguro: si no existe una "Caja 1", crearla por defecto y
        //    asociar todas las sesiones históricas existentes a ella.
        $caja1Id = DB::table('cajas_fisicas')
            ->where('nombre', 'Caja 1')
            ->value('id');

        $caja1Id ??= DB::table('cajas_fisicas')->insertGetId([
            'nombre' => 'Caja 1',
            'activa' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cajas')
            ->whereNull('caja_fisica_id')
            ->update(['caja_fisica_id' => $caja1Id]);

        // 4. Quitar la restricción global de una única caja abierta.
        DB::statement('DROP INDEX IF EXISTS cajas_unica_abierta');

        // 5. Nueva regla: una caja física puede tener a lo sumo una sesión
        //    ABIERTA simultáneamente, pero distintas cajas físicas pueden
        //    estar abiertas al mismo tiempo.
        DB::statement(
            'CREATE UNIQUE INDEX cajas_fisica_abierta '
            .'ON cajas (caja_fisica_id) WHERE estado = \'ABIERTA\''
        );
    }

    /**
     * Reverse the migrations.
     *
     * Restaura el índice único global y elimina la relación con cajas físicas.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cajas_fisica_abierta');
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS cajas_unica_abierta ON cajas ((1)) WHERE estado = \'ABIERTA\'');

        Schema::table('cajas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('caja_fisica_id');
        });

        Schema::dropIfExists('cajas_fisicas');
    }
};
