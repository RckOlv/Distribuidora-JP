<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Índice único parcial: a lo sumo una caja ABIERTA en el sistema.
     *
     * Garantía a nivel de base de datos (además de la lógica de aplicación)
     * para impedir dos cajas abiertas simultáneas aunque lleguen pedidos
     * concurrentes.
     */
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS cajas_unica_abierta ON cajas ((1)) WHERE estado = \'ABIERTA\'');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS cajas_unica_abierta');
    }
};