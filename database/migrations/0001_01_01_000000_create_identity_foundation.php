<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Database.md users/staff/reference_values; M01/M10/M12. PostgreSQL is required. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reference_values', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('category', 50);
            $table->string('code', 64);
            $table->string('label', 160);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->jsonb('metadata')->default('{}');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['category', 'code']);
        });
        Schema::create('staff', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('staff_no', 50)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('gender_category', 50)->default('gender');
            $table->string('gender_code', 24)->nullable();
            $table->string('position_category', 50)->default('position');
            $table->string('position_code', 32);
            $table->date('appointment_date')->nullable();
            $table->text('phone_ciphertext')->nullable();
            $table->text('home_phone_ciphertext')->nullable();
            $table->text('address_ciphertext')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_rider')->default(false);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->foreign(['gender_category', 'gender_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $table->foreign(['position_category', 'position_code'])->references(['category', 'code'])->on('reference_values')->restrictOnDelete();
            $table->index(['gender_category', 'gender_code']);
            $table->index(['position_category', 'position_code']);
            $table->index(['is_active', 'position_code']);
            $table->index(['is_rider', 'is_active']);
        });
        DB::statement("ALTER TABLE staff ADD CONSTRAINT staff_categories CHECK (gender_category = 'gender' AND position_category = 'position')");
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('staff_id')->nullable()->unique()->constrained('staff')->restrictOnDelete();
            $table->string('name', 160);
            $table->string('email', 254);
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password', 255);
            $table->rememberToken();
            $table->string('status', 24)->default('invited');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('invited','active','disabled','locked'))");
        Schema::create('password_reset_tokens', function (Blueprint $table): void {
            $table->string('email', 254)->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('reference_values');
    }
};
