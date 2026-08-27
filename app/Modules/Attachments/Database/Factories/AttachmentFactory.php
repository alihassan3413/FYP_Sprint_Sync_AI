<?php

declare(strict_types=1);

namespace App\Modules\Attachments\Database\Factories;

use App\Models\User;
use App\Modules\Attachments\Models\Attachment;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attachment>
 */
final class AttachmentFactory extends Factory
{
    protected $model = Attachment::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'uploaded_by' => User::factory(),
            'disk' => 'local',
            'path' => 'workspaces/1/attachments/'.fake()->uuid().'.png',
            'name' => fake()->word().'.png',
            'mime' => 'image/png',
            'size' => fake()->numberBetween(2000, 500000),
            'width' => 1200,
            'height' => 800,
        ];
    }
}
