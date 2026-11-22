<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('screening_mailbox_id')->nullable();
            $table->boolean('screening_enabled')->default(false);
        });

        Schema::create('sender_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('address', 320);
            $table->string('destination')->nullable();
            $table->uuid('revision');
            $table->string('previous_destination')->nullable();
            $table->uuid('return_revision')->nullable();
            $table->string('return_destination')->nullable();
            $table->boolean('needs_sync')->default(false);
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'address']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_decisions');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['screening_mailbox_id', 'screening_enabled']));
    }
};
