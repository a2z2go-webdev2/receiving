import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { Label } from '@/components/ui/label';

export function WarehouseField({
    label,
    error,
    children,
}: {
    label: string;
    error?: string;
    children: ReactNode;
}) {
    const renderLabel = () => {
        if (!label) return null;
        if (label.includes('*')) {
            const parts = label.split('*');
            return (
                <>
                    {parts[0]}
                    <span className="ml-0.5 font-bold text-destructive">*</span>
                    {parts.slice(1).join('*')}
                </>
            );
        }
        return label;
    };

    return (
        <div className="space-y-1">
            {label ? <Label className="text-xs">{renderLabel()}</Label> : null}
            {children}
            <InputError message={error} />
        </div>
    );
}

export function WarehouseTextField({
    label,
    value,
    onChange,
    error,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <WarehouseField label={label} error={error}>
            <textarea
                className="min-h-16 w-full rounded-md border border-input bg-transparent px-3 py-1.5 text-xs shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </WarehouseField>
    );
}
