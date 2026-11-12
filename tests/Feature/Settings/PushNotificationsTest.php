<?php

use App\Actions\Account\EraseAccount;
use App\Actions\Account\RevokeSessions;
use App\Enums\FamilyRole;
use App\Enums\LivingStatus;
use App\Enums\MembershipStatus;
use App\Enums\PushTopic;
use App\Models\ChangeRequest;
use App\Models\FamilyEvent;
use App\Models\FamilyMembership;
use App\Models\User;
use App\Notifications\ChangeRequestSubmitted;
use App\Notifications\FamilyEventAnnounced;
use App\Notifications\MorningReminder;
use App\Notifications\TestPush;
use App\Services\Notifications\PushEndpoint;
use App\Support\FuzzyDate\FuzzyDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use NotificationChannels\WebPush\WebPushChannel;

uses(RefreshDatabase::class);

const FCM = 'https://fcm.googleapis.com/fcm/send/abc123';

/** A browser subscription as the page sends it. */
function pushDevice(string $endpoint = FCM): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM', 'auth' => 'tBHItJI5svbpez7KI4CCXg'],
        'contentEncoding' => 'aes128gcm',
    ];
}

function withPush(User $user, string $endpoint = FCM): User
{
    $user->updatePushSubscription($endpoint, pushDevice()['keys']['p256dh'], pushDevice()['keys']['auth'], 'aes128gcm');

    return $user;
}

test('the notifications page needs sign-in and shows only hashes of the person\'s own devices', function () {
    $this->get(route('notifications.edit'))->assertRedirect(route('login'));

    $user = withPush(User::factory()->create());
    withPush(User::factory()->create(), 'https://updates.push.services.mozilla.com/wpush/v2/other');

    $this->actingAs($user)->get(route('notifications.edit'))->assertOk()
        ->assertDontSee('fcm.googleapis.com')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('settings/notifications')
            ->where('devices', [hash('sha256', FCM)])
            ->has('topics', 3)
            ->where('topics.0.enabled', true));
});

test('a browser can register only a real push service as its address', function (string $endpoint, bool $allowed) {
    expect(PushEndpoint::isAllowed($endpoint))->toBe($allowed);
})->with([
    'Chrome' => [FCM, true],
    'Firefox' => ['https://updates.push.services.mozilla.com/wpush/v2/gAAAA', true],
    'Safari' => ['https://web.push.apple.com/QGuQyavXutnMH', true],
    'Edge' => ['https://wns2-par02p.notify.windows.com/w/?token=BQYAAAD', true],
    'plain http' => ['http://fcm.googleapis.com/fcm/send/abc', false],
    'internal service' => ['https://horizon.internal/fcm/send/abc', false],
    'database by name' => ['https://pgsql/', false],
    'an IP address' => ['https://169.254.169.254/latest/meta-data', false],
    'a look-alike host' => ['https://fcm.googleapis.com.attacker.example/x', false],
    'a suffix without the dot' => ['https://evilnotify.windows.com/x', false],
    'credentials in the address' => ['https://user:pass@fcm.googleapis.com/x', false],
    'another port' => ['https://fcm.googleapis.com:8443/x', false],
    'too long' => ['https://fcm.googleapis.com/'.str_repeat('a', 600), false],
]);

test('turning push on stores the device; a refused address or malformed key is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('notifications.subscribe'), pushDevice())->assertSessionHasNoErrors();
    expect($user->pushSubscriptions()->sole())->endpoint->toBe(FCM)->content_encoding->value->toBe('aes128gcm');

    $this->post(route('notifications.subscribe'), pushDevice('http://127.0.0.1:6379/'))->assertSessionHasErrors('endpoint');
    $this->post(route('notifications.subscribe'), [...pushDevice('https://fcm.googleapis.com/fcm/send/two'), 'keys' => ['p256dh' => '<script>', 'auth' => 'x']])
        ->assertSessionHasErrors(['keys.p256dh']);
    $this->post(route('notifications.subscribe'), [...pushDevice('https://fcm.googleapis.com/fcm/send/three'), 'contentEncoding' => 'gzip'])
        ->assertSessionHasErrors('contentEncoding');

    expect($user->pushSubscriptions()->count())->toBe(1);
});

test('a device moves to whoever signs in on it last, and only your own device can be turned off', function () {
    $first = withPush(User::factory()->create());
    $second = User::factory()->create();

    $this->actingAs($second)->post(route('notifications.subscribe'), pushDevice())->assertSessionHasNoErrors();

    expect($first->pushSubscriptions()->count())->toBe(0)
        ->and($second->pushSubscriptions()->count())->toBe(1);

    // Someone else cannot remove it by knowing the address.
    $this->actingAs($first)->delete(route('notifications.unsubscribe'), ['endpoint' => FCM]);
    expect($second->pushSubscriptions()->count())->toBe(1);

    $this->actingAs($second)->delete(route('notifications.unsubscribe'), ['endpoint' => FCM])->assertSessionHasNoErrors();
    expect($second->pushSubscriptions()->count())->toBe(0);
});

test('choosing what to receive', function () {
    $user = withPush(User::factory()->create());

    $this->actingAs($user)->patch(route('notifications.update'), ['topics' => ['events']])->assertSessionHasNoErrors();
    expect($user->refresh()->pushTopics())->toBe([PushTopic::Events])
        ->and($user->wantsPush(PushTopic::Events))->toBeTrue()
        ->and($user->wantsPush(PushTopic::Approvals))->toBeFalse();

    $this->patch(route('notifications.update'), ['topics' => []])->assertSessionHasNoErrors();
    expect($user->refresh()->pushTopics())->toBe([]);

    $this->patch(route('notifications.update'), ['topics' => ['everything']])->assertSessionHasErrors('topics.0');

    // No device, no push, whatever the topics.
    $nobody = User::factory()->create();
    expect($nobody->wantsPush(PushTopic::Approvals))->toBeFalse();
});

test('a test message goes only to someone with a device', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('notifications.test'))->assertSessionHasNoErrors();
    Notification::assertNothingSent();

    withPush($user);
    $this->post(route('notifications.test'))->assertSessionHasNoErrors();
    Notification::assertSentTo($user, TestPush::class, fn (TestPush $n) => $n->via($user) === [WebPushChannel::class]);
});

test('push stops on every device when sessions are revoked or the account is erased', function () {
    $user = withPush(User::factory()->create());
    app(RevokeSessions::class)->all($user);
    expect($user->pushSubscriptions()->count())->toBe(0);

    withPush($user);
    app(EraseAccount::class)->handle($user);
    expect(DB::table('push_subscriptions')->count())->toBe(0);
});

test('approval notifications add push for those who want it, without private details', function () {
    Notification::fake();
    [$family, $owner] = treeFamily();
    withPush($owner);
    $contributor = memberWithRole($family, FamilyRole::Contributor);
    $siti = person($family, 'Siti', ['living_status' => LivingStatus::Living, 'birth' => FuzzyDate::day(1990, 5, 1)]);

    $this->actingAs($contributor)->patch(route('families.members.update', [$family, $siti]), ['version' => 1, 'full_name' => 'Siti Aminah', 'living_status' => 'living']);

    Notification::assertSentTo($owner, ChangeRequestSubmitted::class, function (ChangeRequestSubmitted $notification) use ($owner, $family): bool {
        $message = $notification->toWebPush($owner)->toArray();

        return in_array(WebPushChannel::class, $notification->via($owner), true)
            && $message['title'] === $family->name
            && ! str_contains(json_encode($message), 'Siti')
            && $message['data']['url'] === route('families.changes.index', $family, false);
    });

    $owner->forceFill(['push_topics' => ['events']])->save();
    $notification = new ChangeRequestSubmitted(ChangeRequest::query()->sole(), $family, 'x');
    expect($notification->via($owner))->toBe(['mail']);
});

test('a new event is announced once to the rest of the family when asked', function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-11 09:00', 'Asia/Kuala_Lumpur'));
    [$family, $owner] = treeFamily();
    $editor = memberWithRole($family, FamilyRole::Editor);
    $viewer = memberWithRole($family, FamilyRole::Viewer);
    $suspended = memberWithRole($family, FamilyRole::Viewer);
    FamilyMembership::query()->where('user_id', $suspended->id)->update(['status' => MembershipStatus::Suspended->value]);
    [$otherFamily] = treeFamily();
    $outsider = memberWithRole($otherFamily, FamilyRole::Viewer);

    $this->actingAs($editor)->post(route('families.events.store', $family), [
        'title' => 'Reunion', 'type' => 'reunion', 'starts_at' => '2026-12-25', 'all_day' => true, 'location_text' => 'Kota Belud',
    ])->assertSessionHasNoErrors();

    Notification::assertSentTo([$owner, $viewer], FamilyEventAnnounced::class);
    Notification::assertNotSentTo([$editor, $suspended, $outsider], FamilyEventAnnounced::class);

    $event = FamilyEvent::query()->sole();
    $mail = (new FamilyEventAnnounced($event, $family))->toMail($viewer);
    expect($mail->subject)->toContain('Reunion')
        ->and(implode(' ', $mail->introLines))->toContain('Friday 25 December 2026')->toContain('Kota Belud');

    // Editing does not announce it again; an event not to be announced is not.
    Notification::fake();
    $this->patch(route('families.events.update', [$family, $event]), ['title' => 'Reunion 2026', 'type' => 'reunion', 'starts_at' => '2026-12-25', 'all_day' => true]);
    $this->post(route('families.events.store', $family), ['title' => 'Quiet', 'type' => 'other', 'starts_at' => '2026-12-26', 'all_day' => true, 'notify_members' => false]);
    Notification::assertNothingSent();
});

test('the morning reminder: in the person\'s own morning, once, with only what they may see', function () {
    Notification::fake();
    // 08:30 in Kuala Lumpur, 01:30 in London.
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:30', 'Asia/Kuala_Lumpur'));
    [$family, $owner] = treeFamily();
    withPush($owner);
    $viewer = withPush(memberWithRole($family, FamilyRole::Viewer), 'https://fcm.googleapis.com/fcm/send/viewer');
    $editor = withPush(memberWithRole($family, FamilyRole::Editor), 'https://fcm.googleapis.com/fcm/send/editor');
    $london = withPush(memberWithRole($family, FamilyRole::Editor), 'https://fcm.googleapis.com/fcm/send/london');
    $london->forceFill(['timezone' => 'Europe/London'])->save();

    $siti = person($family, 'Siti', ['living_status' => LivingStatus::Living, 'birth' => FuzzyDate::day(1990, 11, 20)]);
    $ali = person($family, 'Ali', ['living_status' => LivingStatus::Living, 'birth' => FuzzyDate::day(1988, 2, 2)]);
    person($family, 'Hidden', ['living_status' => LivingStatus::Living, 'visibility' => 'private', 'birth' => FuzzyDate::day(1970, 11, 20)]);
    partners($siti, $ali, ['start' => ['year' => 2015, 'month' => 11, 'day' => 20]]);
    FamilyEvent::query()->forceCreate(['family_id' => $family->id, 'title' => 'Kenduri', 'type' => 'celebration', 'starts_at' => CarbonImmutable::parse('2026-11-21 12:00', 'Asia/Kuala_Lumpur')->utc()]);
    FamilyEvent::query()->forceCreate(['family_id' => $family->id, 'title' => 'Not announced', 'type' => 'other', 'notify_members' => false, 'starts_at' => CarbonImmutable::parse('2026-11-21 15:00', 'Asia/Kuala_Lumpur')->utc()]);

    $this->artisan('notifications:morning')->assertSuccessful();
    $this->artisan('notifications:morning')->assertSuccessful();

    Notification::assertSentToTimes($owner, MorningReminder::class, 1);

    // The Owner may see private people; an Editor may not.
    Notification::assertSentTo($owner, MorningReminder::class, fn (MorningReminder $n) => $n->lines === [
        'Birthday today: Hidden, Siti.', 'Anniversary today: Siti & Ali.', 'Tomorrow: Kenduri.',
    ]);
    Notification::assertSentTo($editor, MorningReminder::class, fn (MorningReminder $n) => $n->lines === [
        'Birthday today: Siti.', 'Anniversary today: Siti & Ali.', 'Tomorrow: Kenduri.',
    ]);

    // A Viewer may not see living people's dates: only the event.
    Notification::assertSentTo($viewer, MorningReminder::class, fn (MorningReminder $n) => $n->lines === ['Tomorrow: Kenduri.']);

    // Not yet morning in London.
    Notification::assertNotSentTo($london, MorningReminder::class);

    // Topics switched off: nothing.
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-21 08:10', 'Asia/Kuala_Lumpur'));
    $owner->forceFill(['push_topics' => ['approvals']])->save();
    $this->artisan('notifications:morning')->assertSuccessful();
    Notification::assertNotSentTo($owner, MorningReminder::class);
});
