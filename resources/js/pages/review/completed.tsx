import { Head } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

export default function ReviewCompleted() {
    return (
        <main className="flex min-h-screen items-center justify-center bg-background p-6">
            <Head title="Review completed" />
            <Card className="w-full max-w-lg">
                <CardContent className="flex flex-col items-center py-8 text-center">
                    <div className="mb-4 rounded-full bg-emerald-100 p-4 text-emerald-700">
                        <CheckCircle2 className="size-10" />
                    </div>
                    <h1 className="font-semibold text-2xl">Review completed</h1>
                    <p className="mt-2 text-muted-foreground">
                        The corrected data has been saved and marked as verified. This secure review
                        link is now closed.
                    </p>
                </CardContent>
            </Card>
        </main>
    );
}
