<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('username')->unique();
            $table->integer('likes')->nullable();
            $table->integer('revision')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_failure_reason')->nullable();
            $table->timestamp('next_refresh_due_at')->nullable()->index();
            $table->timestamps();
        });
    }
};
