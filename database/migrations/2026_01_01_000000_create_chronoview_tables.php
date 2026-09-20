<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('chronoview.connection');
    }

    public function up(): void
    {
        $prefix = (string) config('chronoview.table_prefix', 'chronoview_');

        Schema::create($prefix . 'tasks', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name');
            $table->string('type', 20);
            $table->text('command')->nullable();
            $table->string('expression', 100);
            $table->string('timezone', 64)->nullable();
            $table->text('description')->nullable();
            $table->boolean('run_in_background')->default(false);
            $table->boolean('without_overlapping')->default(false);
            $table->boolean('on_one_server')->default(false);
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_finished_at')->nullable();
            $table->string('last_status', 20)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix . 'runs', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('task_id')->constrained($prefix . 'tasks')->cascadeOnDelete();
            $table->string('status', 20);
            $table->string('trigger', 20)->default('schedule');
            $table->timestamp('expected_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->integer('exit_code')->nullable();
            $table->unsignedBigInteger('memory_peak')->nullable();
            $table->string('hostname')->nullable();
            $table->longText('output')->nullable();
            $table->longText('exception')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'expected_at']);
            $table->index(['task_id', 'started_at']);
            $table->index('status');
            $table->index('started_at');
        });

        Schema::create($prefix . 'heartbeats', function (Blueprint $table): void {
            $table->string('hostname')->primary();
            $table->timestamp('beat_at');
        });
    }

    public function down(): void
    {
        $prefix = (string) config('chronoview.table_prefix', 'chronoview_');

        Schema::dropIfExists($prefix . 'runs');
        Schema::dropIfExists($prefix . 'tasks');
        Schema::dropIfExists($prefix . 'heartbeats');
    }
};
