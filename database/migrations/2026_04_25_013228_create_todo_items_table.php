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
        Schema::create('todo_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedTinyInteger('priority')->default(1); // Enum: 0 = low, 1 = medium, 2 = high, 3 = urgent
            $table->unsignedTinyInteger('status')->default(0); // Enum: 0 = pending, 1 = in_progress, 2 = completed, 3 = archived
            $table->integer('order')->default(0);
            $table->timestamp('due_date')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('todo_items')->cascadeOnDelete();
            $table->foreignId('todo_id')->constrained('todos')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index(['priority', 'status']);
            $table->index(['todo_id', 'order']);
            $table->index('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('todo_items');
    }
};
