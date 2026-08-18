import { Head, Link } from '@inertiajs/react';
import { FileClock, Pencil, UploadCloud } from 'lucide-react';
import { EmptyState } from '@/components/receiving/empty-state';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Button } from '@/components/ui/button';
import { useLivePageData } from '@/hooks/use-live-page-data';
import { aiStatusLabel } from '@/lib/upload-status';

type Upload = {
    id: number;
    serial_number: number;
    serial_prefix: string;
    created_at: string;
    file_count: number;
    review_email_status: string;
    ai_status: string;
    review_status: string;
};

type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function UploadHistory({
    uploadType,
    uploads,
}: {
    uploadType: {
        id: number;
        name: string;
        slug: string;
        requires_review: boolean;
        serial_prefix: string;
    };
    uploads: Paginator<Upload>;
}) {
    useLivePageData(['uploads']);

    return (
        <>
            <Head title={`${uploadType.name} Uploads`} />
            <PageShell
                title="My uploads"
                description={
                    uploadType.requires_review
                        ? `Track email delivery, AI extraction, and review progress for your ${uploadType.name} uploads.`
                        : `Track AI extraction progress for your ${uploadType.name} uploads.`
                }
                actions={
                    <Button asChild>
                        <Link href={`/upload/${uploadType.slug}`}>
                            <UploadCloud /> Upload files
                        </Link>
                    </Button>
                }
            >
                <FlashMessage />
                {uploads.data.length === 0 ? (
                    <EmptyState
                        title="No uploads yet"
                        description={`Your completed ${uploadType.name} submissions will appear here.`}
                    />
                ) : (
                    <div className="overflow-hidden rounded-xl border bg-card shadow-sm">
                        <div className="flex items-center gap-2 border-b px-4 py-3">
                            <FileClock className="size-5 text-primary" />
                            <h2 className="font-medium">{uploadType.name} upload history</h2>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="border-b bg-muted/50 text-muted-foreground">
                                    <tr>
                                        <th className="px-4 py-3">Serial number</th>
                                        <th className="px-4 py-3">Files</th>
                                        {uploadType.requires_review && (
                                            <th className="px-4 py-3">Review email</th>
                                        )}
                                        <th className="px-4 py-3">AI extraction</th>
                                        {uploadType.requires_review && (
                                            <th className="px-4 py-3">Review status</th>
                                        )}
                                        <th className="px-4 py-3">Uploaded</th>
                                        {uploadType.requires_review && (
                                            <th className="px-4 py-3 text-right">
                                                <span className="sr-only">Actions</span>
                                            </th>
                                        )}
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {uploads.data.map((upload) => (
                                        <tr key={upload.id} className="hover:bg-muted/30">
                                            <td className="px-4 py-3 font-medium">
                                                {upload.serial_prefix}-{upload.serial_number}
                                            </td>
                                            <td className="px-4 py-3">{upload.file_count}</td>
                                            {uploadType.requires_review && (
                                                <td className="px-4 py-3">
                                                    <StatusBadge
                                                        value={upload.review_email_status}
                                                    />
                                                </td>
                                            )}
                                            <td className="px-4 py-3">
                                                <StatusBadge
                                                    value={upload.ai_status}
                                                    label={aiStatusLabel(upload.ai_status)}
                                                />
                                            </td>
                                            {uploadType.requires_review && (
                                                <td className="px-4 py-3">
                                                    <StatusBadge value={upload.review_status} />
                                                </td>
                                            )}
                                            <td className="whitespace-nowrap px-4 py-3 text-muted-foreground">
                                                {new Date(upload.created_at).toLocaleString()}
                                            </td>
                                            {uploadType.requires_review && (
                                                <td className="px-4 py-3 text-right">
                                                    {upload.review_status === 'verified' && (
                                                        <Button asChild variant="outline" size="sm">
                                                            <Link
                                                                href={`/upload/${uploadType.slug}/uploads/${upload.id}/edit`}
                                                            >
                                                                <Pencil className="mr-1.5 size-3.5" />
                                                                Edit corrected data
                                                            </Link>
                                                        </Button>
                                                    )}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="border-t p-3">
                            <PaginationNav
                                currentPage={uploads.current_page}
                                lastPage={uploads.last_page}
                                previousUrl={uploads.prev_page_url}
                                nextUrl={uploads.next_page_url}
                                label="Upload history pages"
                            />
                        </div>
                    </div>
                )}
            </PageShell>
        </>
    );
}
