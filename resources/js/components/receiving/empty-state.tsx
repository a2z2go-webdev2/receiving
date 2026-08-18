import { Inbox } from 'lucide-react';
import type { ReactNode } from 'react';

export function EmptyState({
    title,
    description,
    action,
}: {
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex min-h-36 flex-col items-center justify-center rounded-lg border border-dashed bg-muted/20 p-5 text-center">
            <div className="mb-2 rounded-full bg-primary/10 p-2 text-primary">
                <Inbox className="size-5" />
            </div>
            <h2 className="font-medium text-sm">{title}</h2>
            <p className="mt-0.5 max-w-md text-muted-foreground text-xs">{description}</p>
            {action && <div className="mt-3">{action}</div>}
        </div>
    );
}
