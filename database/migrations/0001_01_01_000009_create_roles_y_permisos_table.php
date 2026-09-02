<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('permisos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre')->unique();
            $table->string('descripcion')->nullable();
            $table->timestamps();
        });

        Schema::create('permiso_rol', function (Blueprint $table) {
            $table->foreignId('permiso_id')->constrained('permisos')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('roles')->cascadeOnDelete();
            $table->primary(['permiso_id', 'rol_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permiso_rol');
        Schema::dropIfExists('permisos');
        Schema::dropIfExists('roles');
    }
};
