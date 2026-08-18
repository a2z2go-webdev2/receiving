import { ShieldCheck } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="relative flex min-h-svh items-center justify-center overflow-hidden bg-background p-4 sm:p-6">
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_left,var(--color-primary)/0.12,transparent_34%),radial-gradient(circle_at_bottom_right,var(--color-primary)/0.08,transparent_38%)]" />
            <div className="absolute top-8 left-8 hidden items-center gap-2 font-semibold text-sm md:flex">
                <AppLogoIcon className="size-7 fill-current text-primary" />
                Receiving Operations
            </div>
            <Card className="relative w-full max-w-md overflow-hidden border-primary/15 py-0 shadow-2xl shadow-primary/10">
                <CardHeader className="items-center gap-3 border-b bg-primary/[0.04] px-6 py-5 text-center sm:px-8">
                    <div className="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground shadow-lg shadow-primary/20">
                        <ShieldCheck className="size-5" />
                    </div>
                    <div className="space-y-1">
                        <p className="font-medium text-[10px] text-primary uppercase tracking-[0.18em]">
                            Secure access
                        </p>
                        <h1 className="font-semibold text-xl tracking-tight">{title}</h1>
                        <p className="mx-auto max-w-sm text-muted-foreground text-xs leading-5">
                            {description}
                        </p>
                    </div>
                </CardHeader>
                <CardContent className="px-6 py-5 sm:px-8">{children}</CardContent>
            </Card>
        </div>
    );
}
