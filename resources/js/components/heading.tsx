export default function Heading({
    title,
    description,
    variant = 'default',
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
}) {
    return (
        <header className={variant === 'small' ? 'space-y-0.5' : 'mb-5 space-y-0.5'}>
            <h2
                className={
                    variant === 'small'
                        ? 'font-medium text-sm'
                        : 'font-semibold text-lg tracking-tight'
                }
            >
                {title}
            </h2>
            {description && <p className="text-muted-foreground text-xs">{description}</p>}
        </header>
    );
}
