import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/social';

/**
 * "Continue with Google". A normal link, not an Inertia visit: the browser has to leave
 * the app and go to Google.
 */
export default function GoogleButton({
    label = 'Continue with Google',
    separator,
}: {
    label?: string;
    /** Text shown on a divider under the button, e.g. "Or continue with email". */
    separator?: string;
}) {
    return (
        <div className="flex flex-col gap-6">
            <Button variant="outline" className="w-full" asChild>
                <a href={redirect.url('google')} data-test="google-button">
                    <svg
                        viewBox="0 0 24 24"
                        className="size-4"
                        aria-hidden="true"
                        focusable="false"
                    >
                        <path
                            fill="#4285F4"
                            d="M23.5 12.3c0-.8-.1-1.6-.2-2.3H12v4.5h6.5a5.5 5.5 0 0 1-2.4 3.6v3h3.9c2.3-2.1 3.5-5.2 3.5-8.8Z"
                        />
                        <path
                            fill="#34A853"
                            d="M12 24c3.2 0 6-1.1 8-2.9l-3.9-3c-1.1.7-2.5 1.2-4.1 1.2-3.1 0-5.8-2.1-6.7-5H1.2v3.1A12 12 0 0 0 12 24Z"
                        />
                        <path
                            fill="#FBBC05"
                            d="M5.3 14.3a7.2 7.2 0 0 1 0-4.6V6.6H1.2a12 12 0 0 0 0 10.8l4.1-3.1Z"
                        />
                        <path
                            fill="#EA4335"
                            d="M12 4.8c1.8 0 3.3.6 4.6 1.8L20 3.1A12 12 0 0 0 1.2 6.6l4.1 3.1c.9-2.9 3.6-4.9 6.7-4.9Z"
                        />
                    </svg>
                    {label}
                </a>
            </Button>

            {separator && (
                <div className="relative text-center text-xs text-muted-foreground uppercase">
                    <span
                        className="absolute inset-x-0 top-1/2 border-t"
                        aria-hidden="true"
                    />
                    <span className="relative bg-background px-2">
                        {separator}
                    </span>
                </div>
            )}
        </div>
    );
}
