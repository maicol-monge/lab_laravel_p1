<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            // Add nullable first to avoid issues with existing records
            $table->string('dui', 15)->nullable()->unique()->after('puesto');
            $table->string('telefono', 30)->nullable()->unique()->after('dui');
            $table->string('correo', 100)->nullable()->unique()->after('telefono');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('empleados', function (Blueprint $table) {
            // drop unique indexes then columns
            $table->dropUnique('empleados_dui_unique');
            $table->dropUnique('empleados_telefono_unique');
            $table->dropUnique('empleados_correo_unique');
            $table->dropColumn(['dui', 'telefono', 'correo']);
        });
    }
};
