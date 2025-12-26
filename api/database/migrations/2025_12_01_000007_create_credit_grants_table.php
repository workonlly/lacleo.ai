<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_grants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id');
            $table->string('granted_by');
            $table->integer('credits');
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('granted_by');
            // Foreign keys to `users` removed to avoid migration failure when `users` table is absent.
            // Store string IDs; enforce integrity at application layer.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_grants');
    }
};
