import { Download, Share, SquarePlus } from 'lucide-react';
import { useState, useSyncExternalStore } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import {
    installPrompt,
    isAppleMobile,
    isStandalone,
    promptInstall,
    subscribeToInstall,
} from '@/lib/pwa';

/**
 * "Install the app" in the sidebar. Shown only where installing is possible: browsers
 * that offer it (Chrome, Edge, Android) and iPhone/iPad, which need Share → Add to
 * Home Screen. Hidden when the app is already opened as an installed app.
 */
export function InstallApp() {
    const offer = useSyncExternalStore(
        subscribeToInstall,
        installPrompt,
        () => null,
    );
    const [standalone] = useState(isStandalone);
    const [apple] = useState(isAppleMobile);
    const [open, setOpen] = useState(false);

    if (standalone || (!offer && !apple)) {
        return null;
    }

    const install = async () => {
        if (offer && !apple) {
            await promptInstall();

            return;
        }

        setOpen(true);
    };

    return (
        <>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton
                        onClick={() => void install()}
                        tooltip={{ children: 'Install the app' }}
                    >
                        <Download aria-hidden="true" />
                        <span>Install the app</span>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add to your Home Screen</DialogTitle>
                        <DialogDescription>
                            The family tree then opens like an app, full screen
                            and without the browser bar.
                        </DialogDescription>
                    </DialogHeader>
                    <ol className="grid gap-3 text-sm">
                        <li className="flex items-start gap-3">
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                1
                            </span>
                            <span>
                                Tap the Share button{' '}
                                <Share
                                    className="inline size-4 align-text-bottom"
                                    aria-label="(a square with an arrow pointing up)"
                                />{' '}
                                in Safari&rsquo;s toolbar. On an iPhone it is at
                                the bottom of the screen; on an iPad, at the
                                top.
                            </span>
                        </li>
                        <li className="flex items-start gap-3">
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                2
                            </span>
                            <span>
                                Scroll down and tap{' '}
                                <strong className="font-medium">
                                    Add to Home Screen
                                </strong>{' '}
                                <SquarePlus
                                    className="inline size-4 align-text-bottom"
                                    aria-hidden="true"
                                />
                                .
                            </span>
                        </li>
                        <li className="flex items-start gap-3">
                            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                                3
                            </span>
                            <span>
                                Tap <strong className="font-medium">Add</strong>
                                . The Family Tree icon appears on your Home
                                Screen.
                            </span>
                        </li>
                    </ol>
                    <p className="text-sm text-muted-foreground">
                        Using Chrome or another browser on an iPhone? Look for
                        Share in its menu; recent versions offer Add to Home
                        Screen too.
                    </p>
                    <DialogFooter>
                        <Button onClick={() => setOpen(false)}>Done</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
