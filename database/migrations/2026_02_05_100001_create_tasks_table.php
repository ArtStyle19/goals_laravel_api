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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->unsignedInteger('order'); // Sequential order (1, 2, 3...)
            $table->dateTime('scheduled_at'); // Assigned date and time
            $table->enum('status', ['scheduled', 'completed', 'skipped'])->default('scheduled');
            $table->boolean('is_locked')->default(false); // Don't move in replan
            $table->text('notes')->nullable(); // User notes
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            // Indexes
            $table->index(['goal_id', 'status']);
            $table->index(['goal_id', 'scheduled_at']);
            $table->index(['goal_id', 'order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
