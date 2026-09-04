<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega el snapshot del costo unitario histórico de cada detalle de venta.
     *
     * Se congela al momento de la venta (como el precio) para que los reportes
     * de ganancia usen el costo de entonces y no el costo vigente actual.
     * Nullable: una venta puede realizarse sin costo conocido (producto sin
     * valor de costo cargado), en cuyo caso la ganancia no es calculable.
     */
    public function up(): void
    {
        Schema::table('detalles_venta', function (Blueprint $table) {
            $table->decimal('costo_unitario', 12, 2)->nullable()->after('precio_unitario');
        });
    }

    public function down(): void
    {
        Schema::table('detalles_venta', function (Blueprint $table) {
            $table->dropColumn('costo_unitario');
        });
    }
};
