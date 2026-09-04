<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Soporta reimpresión de tickets.
 *
 * Un ticket pasa a poder tener más de un trabajo de impresión: uno original
 * (tipo VENTA) y varios manuales (tipo REIMPRESION). Para conservar la regla
 * de "una impresión original por ticket" se reemplaza el UNIQUE global sobre
 * ticket_id por un índice único parcial que solo aplica a los trabajos VENTA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trabajos_impresion', function (Blueprint $table) {
            $table->dropUnique(['ticket_id']);

            $table->string('tipo', 20)->default('VENTA')->after('ticket_id');
            $table->text('motivo')->nullable()->after('impreso_en');
            $table->foreignId('usuario_id')
                ->nullable()
                ->after('dispositivo_id')
                ->constrained('usuarios')
                ->nullOnDelete();
        });

        // Una sola impresión original (VENTA) por ticket; las reimpresiones
        // (REIMPRESION) pueden multiplicarse.
        DB::statement(
            'CREATE UNIQUE INDEX trabajos_impresion_ticket_venta_unique '
            .'ON trabajos_impresion (ticket_id) WHERE tipo = \'VENTA\''
        );

        Schema::table('trabajos_impresion', function (Blueprint $table) {
            $table->index('estado');
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS trabajos_impresion_ticket_venta_unique');

        Schema::table('trabajos_impresion', function (Blueprint $table) {
            $table->dropIndex(['estado']);
            $table->dropConstrainedForeignId('usuario_id');
            $table->dropColumn(['tipo', 'motivo']);
            $table->unique('ticket_id');
        });
    }
};
