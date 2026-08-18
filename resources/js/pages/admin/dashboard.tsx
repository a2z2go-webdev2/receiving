import { Head, Link } from '@inertiajs/react';
import {
    Bot,
    CalendarDays,
    CheckCircle2,
    Clock3,
    MailWarning,
    UploadCloud,
    UserCheck,
    XCircle,
} from 'lucide-react';
import { EmptyState } from '@/components/receiving/empty-state';
import { PageShell } from '@/components/receiving/page-shell';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useLivePageData } from '@/hooks/use-live-page-data';
import { aiStatusLabel } from '@/lib/upload-status';

type Cards = {
    uploads_today: number;
    uploads_month: number;
    pending_ai: number;
    failed_ai: number;
    pending_reviews: number;
    verified_reviews: number;
    failed_emails: number;
    active_uploaders: number;
};
type Recent = {
    id: number;
    serial_number: number;
    serial_prefix: string;
    upload_type: string;
    uploader: string;
    file_count: number;
    review_email_status: string;
    ai_status: string;
    review_status: string;
    created_at: string;
};

export default function AdminDashboard({
    cards,
    recentUploads,
}: {
    cards: Cards;
    recentUploads: Recent[];
}) {
    useLivePageData(['cards', 'recentUploads']);

    const stats = [
        ['Uploads today', cards.uploads_today, UploadCloud],
        ['Uploads this month', cards.uploads_month, CalendarDays],
        ['Pending AI', cards.pending_ai, Bot],
        ['Failed AI', cards.failed_ai, XCircle],
        ['Pending reviews', cards.pending_reviews, Clock3],
        ['Verified reviews', cards.verified_reviews, CheckCircle2],
        ['Failed review emails', cards.failed_emails, MailWarning],
        ['Active uploaders', cards.active_uploaders, UserCheck],
    ] as const;
    return (
        <>
            <Head title="Operations Overview" />
            <PageShell
                title="Operations overview"
                description="A live control surface for receiving volume, provider failures, extraction work, and human review."
            >
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {stats.map(([label, value, Icon]) => (
                        <Card key={label}>
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-1">
                                <CardTitle className="text-muted-foreground text-xs">
                                    {label}
                                </CardTitle>
                                <Icon className="size-3.5 text-primary" />
                            </CardHeader>
                            <CardContent className="pt-0">
                                <p className="font-semibold text-2xl tracking-tight">{value}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <section className="space-y-2">
                    <div className="flex items-center justify-between">
                        <h2 className="font-semibold text-base">Recent uploads</h2>
                        <Button asChild variant="outline" size="sm">
                            <Link href="/admin/uploads">View all uploads</Link>
                        </Button>
                    </div>
                    {recentUploads.length === 0 ? (
                        <EmptyState
                            title="No receiving activity yet"
                            description="New upload transactions will appear here as soon as an uploader confirms secure staging."
                        />
                    ) : (
                        <div className="overflow-x-auto rounded-lg border bg-card shadow-sm">
                            <table className="w-full text-left text-xs">
                                <thead className="border-b bg-muted/50">
                                    <tr>
                                        <th className="px-3 py-1.5">Serial</th>
                                        <th className="px-3 py-1.5">Type</th>
                                        <th className="px-3 py-1.5">Uploader</th>
                                        <th className="px-3 py-1.5">Files</th>
                                        <th className="px-3 py-1.5">Review email</th>
                                        <th className="px-3 py-1.5">AI</th>
                                        <th className="px-3 py-1.5">Review status</th>
                                        <th className="px-3 py-1.5">Date</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {recentUploads.map((upload) => (
                                        <tr key={upload.id} className="hover:bg-muted/30">
                                            <td className="px-3 py-1.5">
                                                <Link
                                                    className="font-medium text-primary hover:underline"
                                                    href={`/admin/uploads/${upload.id}`}
                                                >
                                                    {upload.serial_prefix}-{upload.serial_number}
                                                </Link>
                                            </td>
                                            <td className="px-3 py-1.5">{upload.upload_type}</td>
                                            <td className="px-3 py-1.5">{upload.uploader}</td>
                                            <td className="px-3 py-1.5">{upload.file_count}</td>
                                            <td className="px-3 py-1.5">
                                                <StatusBadge value={upload.review_email_status} />
                                            </td>
                                            <td className="px-3 py-1.5">
                                                <StatusBadge
                                                    value={upload.ai_status}
                                                    label={aiStatusLabel(upload.ai_status)}
                                                />
                                            </td>
                                            <td className="px-3 py-1.5">
                                                <StatusBadge value={upload.review_status} />
                                            </td>
                                            <td className="px-3 py-1.5 text-muted-foreground">
                                                {new Date(upload.created_at).toLocaleString()}
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

AdminDashboard.layout = {
    breadcrumbs: [{ title: 'Operations overview', href: '/admin/dashboard' }],
};
