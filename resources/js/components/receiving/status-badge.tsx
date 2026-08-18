import { Badge } from '@/components/ui/badge';

const good = new Set([
    'active',
    'sent',
    'clean',
    'valid',
    'compressed',
    'completed',
    'extracted',
    'verified',
    'success',
    'arrived',
    'linked',
    'matched',
]);
const bad = new Set([
    'failed',
    'infected',
    'invalid',
    'suspicious',
    'partial_failed',
    'inactive',
    'deactivated',
    'banned',
    'error',
]);
const waiting = new Set([
    'pending',
    'processing',
    'sending',
    'queued',
    'staging',
    'revision',
    'manual_review',
    'warning',
    'awaiting_purchase_order',
    'missing_po_number',
    'ready_to_link',
    'purchase_order_already_linked',
    'short',
    'over',
    'unverified',
]);
const info = new Set(['info', 'automatic', 'manual']);
const neutral = new Set(['not_required']);

export function StatusBadge({
    value,
    label,
}: {
    value: string | null | undefined;
    label?: string;
}) {
    const status = value ?? 'not available';
    const className = good.has(status)
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200'
        : bad.has(status)
          ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200'
          : waiting.has(status)
            ? 'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200'
            : info.has(status)
              ? 'border-blue-200 bg-blue-50 text-blue-800 dark:border-blue-800 dark:bg-blue-950 dark:text-blue-200'
              : neutral.has(status)
                ? 'border-slate-200 bg-slate-50 text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200'
                : '';

    return (
        <Badge variant="outline" className={className}>
            {label ?? friendlyStatus(status)}
        </Badge>
    );
}

function friendlyStatus(value: string): string {
    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}
