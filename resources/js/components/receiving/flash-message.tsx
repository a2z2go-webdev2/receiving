import { usePage } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';

export function FlashMessage() {
    const { flash } = usePage().props;

    if (!flash?.status) {
        return null;
    }

    return (
        <div
            className="flex items-start gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 text-emerald-900"
            role="status"
        >
            <CheckCircle2 className="mt-0.5 size-4 shrink-0" />
            <p className="text-xs">{flash.status}</p>
        </div>
    );
}
