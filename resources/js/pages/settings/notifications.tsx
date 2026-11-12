import { Head, router, useForm } from '@inertiajs/react';
import { Bell, BellOff, Send } from 'lucide-react';
import type { FormEvent } from 'react';
import { useEffect, useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    currentSubscription,
    endpointHash,
    pushSupport,
    subscribe,
    unsubscribeThisDevice,
} from '@/lib/push';
import type { PushSupport } from '@/lib/push';
import {
    edit,
    subscribe as subscribeRoute,
    test,
    unsubscribe as unsubscribeRoute,
    update,
} from '@/routes/notifications';

type Topic = {
    value: string;
    label: string;
    description: string;
    enabled: boolean;
};

type Props = {
    vapidPublicKey: string | null;
    devices: string[];
    topics: Topic[];
};

type DeviceState = 'checking' | 'on' | 'off' | 'blocked';

export default function Notifications({
    vapidPublicKey,
    devices,
    topics,
}: Props) {
    const [support] = useState<PushSupport>(pushSupport);
    const [device, setDevice] = useState<DeviceState>('checking');
    const [busy, setBusy] = useState(false);
    const [problem, setProblem] = useState<string>();

    const form = useForm({
        topics: topics.filter((t) => t.enabled).map((t) => t.value),
    });

    // Is this browser one of the devices the server sends to?
    useEffect(() => {
        let cancelled = false;

        void (async () => {
            if (support !== 'supported') {
                return;
            }

            if (Notification.permission === 'denied') {
                if (!cancelled) {
                    setDevice('blocked');
                }

                return;
            }

            const subscription = await currentSubscription();
            const known =
                subscription !== null &&
                devices.includes(await endpointHash(subscription.endpoint));

            if (!cancelled) {
                setDevice(known ? 'on' : 'off');
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [devices, support]);

    const turnOn = async () => {
        if (!vapidPublicKey) {
            setProblem('Push notifications are not set up on this server yet.');

            return;
        }

        setBusy(true);
        setProblem(undefined);

        try {
            const subscription = await subscribe(vapidPublicKey);

            if (!subscription) {
                setDevice(
                    Notification.permission === 'denied' ? 'blocked' : 'off',
                );
                setBusy(false);

                return;
            }

            router.post(
                subscribeRoute.url(),
                {
                    endpoint: subscription.endpoint ?? '',
                    keys: subscription.keys ?? {},
                    contentEncoding: (
                        PushManager as unknown as {
                            supportedContentEncodings?: string[];
                        }
                    ).supportedContentEncodings?.includes('aes128gcm')
                        ? 'aes128gcm'
                        : 'aesgcm',
                },
                {
                    preserveScroll: true,
                    onError: (errors) =>
                        setProblem(
                            errors.endpoint ??
                                'This browser could not be set up for push notifications.',
                        ),
                    onFinish: () => setBusy(false),
                },
            );
        } catch {
            setProblem(
                'This browser could not be set up for push notifications.',
            );
            setBusy(false);
        }
    };

    const turnOff = async () => {
        setBusy(true);
        const endpoint = await unsubscribeThisDevice();

        if (!endpoint) {
            setDevice('off');
            setBusy(false);

            return;
        }

        router.delete(unsubscribeRoute.url(), {
            data: { endpoint },
            preserveScroll: true,
            onFinish: () => setBusy(false),
        });
    };

    const toggleTopic = (value: string, on: boolean) =>
        form.setData(
            'topics',
            on
                ? [...form.data.topics, value]
                : form.data.topics.filter((topic) => topic !== value),
        );

    const saveTopics = (event: FormEvent) => {
        event.preventDefault();
        form.patch(update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Notification settings" />

            <h1 className="sr-only">Notification settings</h1>

            <div className="space-y-10">
                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="Push notifications on this device"
                        description="Email remains the main way we reach you. Push notifications are an extra, for each phone or computer you turn them on."
                    />

                    {support === 'install-first' && (
                        <p className="rounded-lg border bg-muted/50 p-3 text-sm">
                            On iPhone and iPad, push notifications only work in
                            the app added to your Home Screen. Use “Install the
                            app” in the menu, open the family tree from your
                            Home Screen, and come back here.
                        </p>
                    )}

                    {support === 'unsupported' && (
                        <p className="rounded-lg border bg-muted/50 p-3 text-sm">
                            This browser cannot receive push notifications.
                        </p>
                    )}

                    {support === 'supported' && (
                        <div className="grid gap-3">
                            <p className="text-sm" role="status">
                                {device === 'checking' && 'Checking…'}
                                {device === 'on' &&
                                    'Push notifications are on for this device.'}
                                {device === 'off' &&
                                    (busy
                                        ? 'Setting up this device… This can take a few seconds.'
                                        : 'Push notifications are off for this device.')}
                                {device === 'blocked' &&
                                    'Notifications are blocked for this site in your browser settings. Allow them there, then reload this page.'}
                            </p>

                            <div className="flex flex-wrap gap-2">
                                {device === 'on' ? (
                                    <>
                                        <Button
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() => void turnOff()}
                                        >
                                            <BellOff aria-hidden="true" />
                                            Turn off on this device
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() =>
                                                router.post(
                                                    test.url(),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Send aria-hidden="true" />
                                            Send a test
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        disabled={
                                            busy ||
                                            device === 'checking' ||
                                            device === 'blocked'
                                        }
                                        onClick={() => void turnOn()}
                                    >
                                        <Bell aria-hidden="true" />
                                        Turn on for this device
                                    </Button>
                                )}
                            </div>

                            {problem && (
                                <p
                                    role="alert"
                                    className="text-sm text-red-600 dark:text-red-400"
                                >
                                    {problem}
                                </p>
                            )}

                            <p className="text-sm text-muted-foreground">
                                Messages can appear on a locked screen, so they
                                only say which family and what happened, plus
                                the names of people whose birthdays and
                                anniversaries you can already see. Logging out
                                turns push off on this device.
                            </p>
                        </div>
                    )}
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title="What to send"
                        description="Applies to every device where push notifications are on."
                    />

                    <form onSubmit={saveTopics} className="grid gap-4">
                        {topics.map((topic) => (
                            <label
                                key={topic.value}
                                className="flex items-start gap-3 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    className="mt-0.5 size-4 rounded border-input"
                                    checked={form.data.topics.includes(
                                        topic.value,
                                    )}
                                    onChange={(e) =>
                                        toggleTopic(
                                            topic.value,
                                            e.target.checked,
                                        )
                                    }
                                />
                                <span>
                                    <span className="font-medium">
                                        {topic.label}
                                    </span>
                                    <span className="block text-muted-foreground">
                                        {topic.description}
                                    </span>
                                </span>
                            </label>
                        ))}

                        <div>
                            <Button
                                type="submit"
                                disabled={form.processing || !form.isDirty}
                            >
                                Save
                            </Button>
                        </div>
                    </form>
                </section>
            </div>
        </>
    );
}

Notifications.layout = {
    breadcrumbs: [
        {
            title: 'Notification settings',
            href: edit(),
        },
    ],
};
