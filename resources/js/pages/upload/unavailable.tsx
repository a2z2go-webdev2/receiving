import { Head } from '@inertiajs/react';
import { CircleOff } from 'lucide-react';
import { PageShell } from '@/components/receiving/page-shell';

export default function UploadUnavailable({
    uploadType,
}: {
    uploadType: { name: string; slug: string };
}) {
    return (
        <>
            <Head title={`${uploadType.name} Uploads Unavailable`} />
            <PageShell
                title="Upload lane unavailable"
                description={`${uploadType.name} receiving is currently turned off by an administrator.`}
            >
                <section className="mx-auto flex w-full max-w-xl flex-col items-center rounded-xl border bg-card px-6 py-10 text-center shadow-sm">
                    <div className="flex size-12 items-center justify-center rounded-full bg-amber-100 text-amber-800">
                        <CircleOff className="size-6" />
                    </div>
                    <h2 className="mt-4 font-semibold text-lg">Uploads are temporarily paused</h2>
                    <p className="mt-2 max-w-md text-muted-foreground text-sm">
                        You cannot submit files to this lane right now. No verification code was
                        sent. Please try again later or contact your administrator if this lane
                        should be available.
                    </p>
                </section>
            </PageShell>
        </>
    );
}
