import { Head, Link } from '@inertiajs/react';
import { ArrowRight, FileClock, UploadCloud } from 'lucide-react';
import { EmptyState } from '@/components/receiving/empty-state';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { aiStatusLabel } from '@/lib/upload-status';

type UploadType = { id: number; name: string; slug: string };
type Upload = {
    id: number;
    serial_number: number;
    upload_type: string;
    created_at: string;
    file_count: number;
    review_email_status: string;
    ai_status: string;
    review_status: string;
    can_resend: boolean;
};

export default function UploaderDashboard({
    uploadTypes,
    uploads,
}: {
    uploadTypes: UploadType[];
    uploads: Upload[];
}) {
    return (
        <>
            <Head title="My Receiving" />
            <PageShell
                title="My receiving"
                description="Choose the company receiving lane, upload scanned documents, and track every processing step."
            >
                <FlashMessage />
                <section aria-labelledby="available-upload-pages">
                    <div className="mb-3 flex items-center gap-2">
                        <UploadCloud className="size-5 text-primary" />
                        <h2 id="available-upload-pages" className="font-semibold text-lg">
                            Available upload pages
                        </h2>
                    </div>
                    {uploadTypes.length === 0 ? (
                        <EmptyState
                            title="No receiving lanes assigned"
                            description="Ask an administrator to assign at least one upload type to your account."
                        />
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                            {uploadTypes.map((type) => (
                                <Card
                                    key={type.id}
                                    className="group border-primary/15 transition hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                                >
                                    <CardHeader>
                                        <div className="mb-2 flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                            <UploadCloud className="size-5" />
                                        </div>
                                        <CardTitle>{type.name} Receiving</CardTitle>
                                        <CardDescription>
                                            Upload invoices, receipts, IDs, and delivery documents.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <Button asChild className="w-full">
                                            <Link href={`/upload/${type.slug}`}>
                                                Open upload page <ArrowRight />
                                            </Link>
                                        </Button>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    )}
                </section>

                <section aria-labelledby="recent-uploads" className="space-y-3">
                    <div className="flex items-center gap-2">
                        <FileClock className="size-5 text-primary" />
                        <h2 id="recent-uploads" className="font-semibold text-lg">
                            My recent uploads
                        </h2>
                    </div>
                    {uploads.length === 0 ? (
                        <EmptyState
                            title="Your upload history starts here"
                            description="Open an assigned receiving lane above to submit the first document set."
                        />
                    ) : (
                        <div className="overflow-x-auto rounded-xl border bg-card shadow-sm">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b bg-muted/50 text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Serial</th>
                                        <th className="px-4 py-3">Type</th>
                                        <th className="px-4 py-3">Files</th>
                                        <th className="px-4 py-3">Review email</th>
                                        <th className="px-4 py-3">AI</th>
                                        <th className="px-4 py-3">Review status</th>
                                        <th className="px-4 py-3">Uploaded</th>
                                        <th className="px-4 py-3">
                                            <span className="sr-only">Actions</span>
                                        </th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {uploads.map((upload) => (
                                        <tr key={upload.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">
                                                SN-{upload.serial_number}
                                            </td>
                                            <td className="px-4 py-3">{upload.upload_type}</td>
                                            <td className="px-4 py-3">{upload.file_count}</td>
                                            <td className="px-4 py-3">
                                                <StatusBadge value={upload.review_email_status} />
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge
                                                    value={upload.ai_status}
                                                    label={aiStatusLabel(upload.ai_status)}
                                                />
                                            </td>
                                            <td className="px-4 py-3">
                                                <StatusBadge value={upload.review_status} />
                                            </td>
                                            <td className="px-4 py-3 text-muted-foreground">
                                                {new Date(upload.created_at).toLocaleString()}
                                            </td>
                                            <td className="px-4 py-3 text-right">
                                                <Button asChild variant="outline" size="sm">
                                                    <Link href={`/receiving/uploads/${upload.id}`}>
                                                        View
                                                    </Link>
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </section>
            </PageShell>
        </>
    );
}

UploaderDashboard.layout = {
    breadcrumbs: [{ title: 'My receiving', href: '/uploader/dashboard' }],
};
