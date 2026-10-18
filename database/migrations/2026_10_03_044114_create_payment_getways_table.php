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
        Schema::create('payment_getways', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->string('base_url')->nullable();
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->string('app_key')->nullable();
            $table->text('app_secret')->nullable();
            $table->string('store_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_testmode')->default(false);
            $table->boolean('is_localhost')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_getways');
    }
};
