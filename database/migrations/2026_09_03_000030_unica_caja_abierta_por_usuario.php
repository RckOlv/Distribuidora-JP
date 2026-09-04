<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Índice único parcial: un usuario puede tener a lo sumo una sesión de
     * caja ABIERTA simultáneamente, garantizado a nivel de base de datos.
     *
     * Complementa a «cajas_fisica_abierta»: esa impide dos sesiones abiertas
     * en una misma caja física; esta impide que un mismo cajero tenga dos
     * sesiones abiertas en cajas físicas distintas.
     *
     * Es seguro sobre los datos históricos actuales: antes de la existencia de
     * las cajas físicas siempre hubo como máximo una caja abierta en todo el
     * sistema, por lo que la combinación (usuario_abre_id, ABIERTA) ya es única.
     */
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS cajas_usuario_abierta '
            .'ON cajas (usuario_abre_id) WHERE estado = \'ABIERTA\''
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cajas_usuario_abierta');
    }
};
