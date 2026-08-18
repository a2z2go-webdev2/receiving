import { Check, X } from 'lucide-react';
import { useMemo } from 'react';
import { cn } from '@/lib/utils';

type Rule = {
    label: string;
    test: (password: string) => boolean;
};

const rules: Rule[] = [
    { label: 'At least 12 characters', test: (p) => p.length >= 12 },
    { label: 'Contains uppercase letter', test: (p) => /[A-Z]/.test(p) },
    { label: 'Contains lowercase letter', test: (p) => /[a-z]/.test(p) },
    { label: 'Contains a number', test: (p) => /\d/.test(p) },
    { label: 'Contains a symbol', test: (p) => /[^A-Za-z0-9]/.test(p) },
];

export function PasswordChecklist({
    password,
    className,
}: {
    password: string;
    className?: string;
}) {
    const results = useMemo(
        () =>
            rules.map((rule) => ({ ...rule, passed: password.length > 0 && rule.test(password) })),
        [password],
    );

    if (password.length === 0) return null;

    return (
        <ul className={cn('space-y-1 text-xs', className)}>
            {results.map((rule) => (
                <li key={rule.label} className="flex items-center gap-1.5">
                    {rule.passed ? (
                        <Check className="size-3.5 text-green-500" />
                    ) : (
                        <X className="size-3.5 text-red-500" />
                    )}
                    <span className={cn(rule.passed ? 'text-green-600' : 'text-red-500')}>
                        {rule.label}
                    </span>
                </li>
            ))}
        </ul>
    );
}
