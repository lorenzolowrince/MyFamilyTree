<?php

namespace App\Http\Controllers\Settings;

use App\Enums\PushTopic;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\TestPush;
use App\Services\Notifications\PushEndpoint;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Notifications: push notifications on this device, and which kinds to receive.
 * Like the other account settings, it only ever acts on the signed-in person's own data.
 */
class NotificationController extends Controller
{
    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $enabled = $user->pushTopics();

        return Inertia::render('settings/notifications', [
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            // Hashes only: the page hashes its own subscription to see if this device is on.
            'devices' => $user->pushSubscriptions()->pluck('endpoint')
                ->map(fn (string $endpoint): string => hash('sha256', $endpoint))
                ->values()
                ->all(),
            'topics' => array_map(fn (PushTopic $topic): array => [
                'value' => $topic->value,
                'label' => $topic->label(),
                'description' => $topic->description(),
                'enabled' => in_array($topic, $enabled, true),
            ], PushTopic::cases()),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'topics' => ['present', 'array'],
            'topics.*' => ['string', Rule::enum(PushTopic::class)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['push_topics' => array_values(array_unique($data['topics']))])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return back();
    }

    /** This browser turned push on: remember where to send messages. */
    public function subscribe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushEndpoint::MAX_LENGTH, function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || ! PushEndpoint::isAllowed($value)) {
                    $fail(__('This browser\'s push service is not supported.'));
                }
            }],
            'keys.p256dh' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_\-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_\-]+={0,2}$/'],
            'contentEncoding' => ['nullable', 'string', Rule::in(['aesgcm', 'aes128gcm'])],
        ]);

        /** @var User $user */
        $user = $request->user();

        // A device used by several accounts receives only the last one's messages.
        $user->updatePushSubscription(
            $data['endpoint'],
            $data['keys']['p256dh'],
            $data['keys']['auth'],
            $data['contentEncoding'] ?? 'aes128gcm',
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Push notifications are on for this device.')]);

        return back();
    }

    /** This browser turned push off. Only the person's own subscription can be removed. */
    public function unsubscribe(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:'.PushEndpoint::MAX_LENGTH],
        ]);

        /** @var User $user */
        $user = $request->user();
        $user->deletePushSubscription($data['endpoint']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Push notifications are off for this device.')]);

        return back();
    }

    public function test(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->pushSubscriptions()->exists()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Turn on push notifications on this device first.')]);

            return back();
        }

        $user->notifyNow(new TestPush);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('A test message was sent. It should arrive within a minute.')]);

        return back();
    }
}
