<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\NotificationPreferenceUpdateRequest;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\NotificationType;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class NotificationPreferenceController extends Controller
{
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Notifications', [
            'groups' => $this->groups($request->user()),
        ]);
    }

    public function update(NotificationPreferenceUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        foreach ($request->validated('preferences') as $preference) {
            NotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $user->id,
                    'type' => $preference['type'],
                    'channel' => $preference['channel'],
                ],
                ['enabled' => $preference['enabled']],
            );
        }

        return to_route('notification-preferences.edit');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function groups(User $user): array
    {
        $saved = NotificationPreference::query()
            ->where('user_id', $user->id)
            ->get()
            ->keyBy(fn (NotificationPreference $preference) => "{$preference->type->value}:{$preference->channel->value}");

        $items = collect(NotificationType::values())->map(function (NotificationType $type) use ($saved) {
            return [
                'type' => $type->value,
                'label' => $type->label(),
                'group' => $type->group(),
                'channels' => collect($type->channels())->map(function ($channel) use ($type, $saved) {
                    $key = "{$type->value}:{$channel->value}";

                    return [
                        'channel' => $channel->value,
                        'label' => $channel->label(),
                        'enabled' => $saved->has($key) ? $saved->get($key)->enabled : true,
                    ];
                })->values()->all(),
            ];
        });

        return $items->groupBy('group')->map(fn ($groupItems, $group) => [
            'group' => $group,
            'items' => $groupItems->values()->all(),
        ])->values()->all();
    }
}
