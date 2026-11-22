<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_drafts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('context', 64);
            $table->longText('content');
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
            $table->unique(['user_id', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_drafts');
    }
};
