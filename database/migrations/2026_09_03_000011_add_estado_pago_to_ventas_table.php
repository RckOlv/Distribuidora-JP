<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el estado de pago a las ventas.
     *
     * PAGADA (default): la venta está completa y contabilizada.
     * PENDIENTE_PAGO: venta creada pero cuyo medio (transferencia/tarjeta)
     * todavía no fue confirmado por el cajero. Mientras está pendiente NO se
     * contabiliza como venta definitiva: no genera movimiento de caja, ticket,
     * trabajo de impresión ni auditoría de venta/caja.
     */
    public function up(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->string('estado_pago', 30)->default('PAGADA')->after('total');
            $table->index('estado_pago');
        });
    }

    public function down(): void
    {
        Schema::table('ventas', function (Blueprint $table) {
            $table->dropIndex(['estado_pago']);
            $table->dropColumn('estado_pago');
        });
    }
};
