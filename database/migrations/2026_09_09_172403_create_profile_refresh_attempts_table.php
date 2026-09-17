<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_refresh_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('profile_id');
            $table->integer('attempt_number');
            $table->string('outcome');
            $table->integer('http_status')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['profile_id', 'created_at']);
        });
    }
};
