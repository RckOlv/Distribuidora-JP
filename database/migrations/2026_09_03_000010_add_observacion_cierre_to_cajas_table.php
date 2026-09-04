<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega la observación del cierre de caja (nullable).
     *
     * Se exige (obligatoria) en el backend cuando el efectivo contado difiere
     * del esperado, para dejar constancia de la diferencia. Cuando no hay
     * diferencia la observación es opcional y queda null.
     */
    public function up(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->text('observacion_cierre')->nullable()->after('diferencia');
        });
    }

    public function down(): void
    {
        Schema::table('cajas', function (Blueprint $table) {
            $table->dropColumn('observacion_cierre');
        });
    }
};
