<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Tracks consecutive failed login attempts. Resets to 0 on successful login
            // or when an admin explicitly unlocks the account.
            $table->unsignedSmallInteger('failed_login_attempts')->default(0)->after('is_active');

            // Set by the login handler when failed_login_attempts reaches the threshold (10).
            // NULL means the account is not locked. Admin must reset this to unlock.
            $table->timestamp('locked_at')->nullable()->after('failed_login_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'locked_at']);
        });
    }
};
