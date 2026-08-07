<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if ($this->foreignKeyExists()) {
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_workspace_id')
                ->references('id')
                ->on('workspaces')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_workspace_id']);
        });
    }

    private function foreignKeyExists(): bool
    {
        return collect(Schema::getForeignKeys('users'))
            ->contains(fn (array $foreignKey) => $foreignKey['columns'] === ['current_workspace_id']);
    }
};
