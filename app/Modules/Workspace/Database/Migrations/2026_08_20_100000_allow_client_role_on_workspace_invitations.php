<?php

use App\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen the enum column to a plain string so invitation roles stay in sync
     * with UserRole::invitationRoles() without another schema change.
     */
    public function up(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->string('role')->default(UserRole::MEMBER->value)->change();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_invitations', function (Blueprint $table) {
            $table->enum('role', [UserRole::ADMIN->value, UserRole::MEMBER->value])
                ->default(UserRole::MEMBER->value)
                ->change();
        });
    }
};
