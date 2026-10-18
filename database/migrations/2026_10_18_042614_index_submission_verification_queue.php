<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** M07 / UI26: date-ordered queue and submission date-range filters. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_submissions', function (Blueprint $table): void {
            $table->index(['submitted_at', 'id'], 'task_submissions_verification_queue_index');
        });
    }

    public function down(): void
    {
        Schema::table('task_submissions', function (Blueprint $table): void {
            $table->dropIndex('task_submissions_verification_queue_index');
        });
    }
};
