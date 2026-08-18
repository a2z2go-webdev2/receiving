import { Head, router } from '@inertiajs/react';
import { Power } from 'lucide-react';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Button } from '@/components/ui/button';
import { LegacyImportDialog } from '@/components/admin/legacy-import-dialog';

type UploadType = { id: number; name: string; slug: string; is_active: boolean };

export default function SettingsIndex({ uploadTypes }: { uploadTypes: UploadType[] }) {
    return (
        <>
            <Head title="Upload Lanes" />
            <PageShell
                title="Upload lanes"
                description="Choose which receiving lanes uploaders can open and manage legacy data imports."
            >
                <FlashMessage />
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {uploadTypes.map((type) => (
                        <div
                            key={type.id}
                            className="flex flex-col justify-between gap-4 rounded-lg border bg-card p-4 shadow-sm"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="font-medium text-base">{type.name}</p>
                                    <p className="mt-0.5 text-muted-foreground text-xs">/{type.slug}</p>
                                    <div className="mt-2">
                                        <StatusBadge value={type.is_active ? 'active' : 'inactive'} />
                                    </div>
                                </div>
                                <Button
                                    size="icon"
                                    variant={type.is_active ? 'outline' : 'default'}
                                    onClick={() =>
                                        router.post(`/admin/upload-types/${type.slug}/toggle`)
                                    }
                                    aria-label={`${type.is_active ? 'Disable' : 'Enable'} ${type.name}`}
                                >
                                    <Power className="h-4 w-4" />
                                </Button>
                            </div>
                            <div className="border-t pt-3 flex justify-end">
                                <LegacyImportDialog uploadType={type} />
                            </div>
                        </div>
                    ))}
                </div>
            </PageShell>
        </>
    );
}

SettingsIndex.layout = {
    breadcrumbs: [{ title: 'Upload lanes', href: '/admin/receiving-settings' }],
};
