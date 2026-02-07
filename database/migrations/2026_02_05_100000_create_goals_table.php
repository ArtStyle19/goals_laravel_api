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
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('deadline');
            $table->unsignedInteger('session_duration_minutes'); // 30, 45, 60, 90, 120
            $table->json('availability_rules'); // [{ day, start, end }, ...]
            $table->enum('status', ['draft', 'active', 'completed'])->default('draft');
            $table->unsignedInteger('total_sessions')->nullable(); // Calculated
            $table->decimal('total_hours', 5, 1)->nullable(); // Calculated
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            // Indexes
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
