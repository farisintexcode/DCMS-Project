<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Database.md clients/user_client_scopes; Plan.md §4. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('code', 50)->unique();
            $table->string('name', 200);
            $table->boolean('is_active')->default(true)->index();
            $table->char('default_currency', 3)->default('MYR');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
        Schema::create('user_client_scopes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('granted_by')->index()->constrained('users')->restrictOnDelete();
            $table->timestampTz('valid_from')->useCurrent();
            $table->timestampTz('valid_to')->nullable();
            $table->text('reason');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->index(['client_id', 'valid_to', 'user_id']);
        });
        DB::statement('CREATE UNIQUE INDEX user_client_scopes_current_unique ON user_client_scopes (user_id, client_id) WHERE valid_to IS NULL');
        DB::statement('ALTER TABLE user_client_scopes ADD CONSTRAINT scopes_validity CHECK ((valid_to IS NULL OR valid_to >= valid_from) AND length(trim(reason)) > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_client_scopes');
        Schema::dropIfExists('clients');
    }
};
