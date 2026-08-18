import { Head, Link } from '@inertiajs/react';
import { FileCheck2, ShieldCheck } from 'lucide-react';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLivePageData } from '@/hooks/use-live-page-data';
import { aiStatusLabel } from '@/lib/upload-status';

type Upload = {
    serial_number: number;
    upload_type: string;
    uploader_email: string;
    created_at: string;
    review_email_status: string;
    ai_status: string;
    review_status: string;
    files: { name: string; validation_status: string; virus_scan_status: string }[];
};

export default function TransactionView({ upload }: { upload: Upload }) {
    useLivePageData(['upload']);

    return (
        <main className="min-h-screen bg-background p-4 md:p-8">
            <Head title={`Receiving SN-${upload.serial_number}`} />
            <div className="mx-auto max-w-4xl space-y-5">
                <header className="flex items-center gap-3">
                    <div className="flex size-11 items-center justify-center rounded-xl bg-primary text-primary-foreground">
                        <ShieldCheck />
                    </div>
                    <div>
                        <p className="font-semibold">Receiving Operations</p>
                        <p className="text-muted-foreground text-sm">
                            Secure transaction notification
                        </p>
                    </div>
                </header>
                <Card>
                    <CardHeader>
                        <CardTitle>
                            {upload.upload_type} · SN-{upload.serial_number}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <p>
                                <span className="text-muted-foreground text-sm">Uploader</span>
                                <br />
                                {upload.uploader_email}
                            </p>
                            <p>
                                <span className="text-muted-foreground text-sm">Uploaded</span>
                                <br />
                                {new Date(upload.created_at).toLocaleString()}
                            </p>
                        </div>
                        <div className="grid gap-3 sm:grid-cols-3">
                            {[
                                { label: 'Review email', value: upload.review_email_status },
                                {
                                    label: 'AI extraction',
                                    value: upload.ai_status,
                                    valueLabel: aiStatusLabel(upload.ai_status),
                                },
                                { label: 'Review status', value: upload.review_status },
                            ].map(({ label, value, valueLabel }) => (
                                <div key={label} className="rounded-lg border p-3">
                                    <p className="mb-2 text-muted-foreground text-xs">{label}</p>
                                    <StatusBadge value={value} label={valueLabel} />
                                </div>
                            ))}
                        </div>
                        <section>
                            <h2 className="mb-2 font-medium">Received files</h2>
                            <ul className="divide-y rounded-lg border">
                                {upload.files.map((file) => (
                                    <li
                                        key={file.name}
                                        className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center"
                                    >
                                        <FileCheck2 className="size-5 text-primary" />
                                        <span className="min-w-0 flex-1 truncate text-sm">
                                            {file.name}
                                        </span>
                                        <StatusBadge value={file.validation_status} />
                                        <StatusBadge value={file.virus_scan_status} />
                                    </li>
                                ))}
                            </ul>
                        </section>
                        <p className="text-muted-foreground text-sm">
                            For document contents and extracted data, sign in with an authorized
                            account. This email link intentionally does not expose raw storage URLs.
                        </p>
                        <Button asChild>
                            <Link href="/login">Sign in to Receiving Operations</Link>
                        </Button>
                    </CardContent>
                </Card>
            </div>
        </main>
    );
}
