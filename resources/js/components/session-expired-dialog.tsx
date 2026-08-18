import { usePage } from '@inertiajs/react';
import { ClockAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export const SESSION_EXPIRED_EVENT = 'receiving:session-expired';

export function announceSessionExpired(message?: string) {
    window.dispatchEvent(
        new CustomEvent(SESSION_EXPIRED_EVENT, {
            detail: {
                message:
                    message ??
                    'Your secure session expired. Continue to sign in or verify access again.',
            },
        }),
    );
}

export function SessionExpiredDialog() {
    const { flash } = usePage().props;
    const [message, setMessage] = useState<string | null>(flash.sessionExpired ?? null);

    useEffect(() => {
        if (flash.sessionExpired) {
            setMessage(flash.sessionExpired);
        }
    }, [flash.sessionExpired]);

    useEffect(() => {
        function handleExpired(event: Event) {
            const detail = (event as CustomEvent<{ message?: string }>).detail;
            setMessage(
                detail?.message ??
                    'Your secure session expired. Continue to sign in or verify access again.',
            );
        }

        window.addEventListener(SESSION_EXPIRED_EVENT, handleExpired);

        return () => window.removeEventListener(SESSION_EXPIRED_EVENT, handleExpired);
    }, []);

    return (
        <Dialog open={message !== null} onOpenChange={(open) => !open && setMessage(null)}>
            <DialogContent hideCloseButton>
                <DialogHeader>
                    <div className="mb-1 flex size-11 items-center justify-center rounded-full bg-amber-100 text-amber-800">
                        <ClockAlert className="size-6" />
                    </div>
                    <DialogTitle>Session expired</DialogTitle>
                    <DialogDescription>{message}</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button onClick={() => window.location.reload()}>Continue securely</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
