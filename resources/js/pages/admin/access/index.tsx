import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Checkbox } from '@/components/ui/checkbox';

type UploadType = { id: number; name: string; slug: string; is_active: boolean };
type UserRow = {
    id: number;
    name: string;
    email: string;
    status: string;
    role: string;
    upload_type_ids: number[];
};
type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function AccessIndex({
    uploadTypes,
    users,
}: {
    uploadTypes: UploadType[];
    users: Paginator<UserRow>;
}) {
    const [selections, setSelections] = useState<Record<number, number[]>>(() =>
        Object.fromEntries(users.data.map((user) => [user.id, user.upload_type_ids])),
    );

    useEffect(() => {
        setSelections(
            Object.fromEntries(users.data.map((user) => [user.id, user.upload_type_ids])),
        );
    }, [users.data]);

    function toggle(userId: number, typeId: number) {
        const currentIds = selections[userId] || [];
        const nextIds = currentIds.includes(typeId)
            ? currentIds.filter((id) => id !== typeId)
            : [...currentIds, typeId];

        setSelections((current) => ({
            ...current,
            [userId]: nextIds,
        }));

        router.put(
            `/admin/upload-access/${userId}`,
            { upload_type_ids: nextIds },
            {
                preserveScroll: true,
                onError: () => {
                    setSelections((current) => ({
                        ...current,
                        [userId]: currentIds,
                    }));
                },
            },
        );
    }

    return (
        <>
            <Head title="Upload Access" />
            <PageShell
                title="Upload access"
                description="Control exactly which receiving lanes each uploader can enter. Removed access is enforced immediately on the server."
            >
                <FlashMessage />
                <div className="overflow-x-auto rounded-lg border bg-card">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="sticky left-0 bg-muted/95 px-3 py-1.5">User</th>
                                {uploadTypes.map((type) => (
                                    <th key={type.id} className="min-w-32 px-3 py-1.5 text-center">
                                        {type.name}
                                    </th>
                                ))}
                                <th className="px-3 py-1.5">Status</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {users.data.map((user) => (
                                <tr key={user.id}>
                                    <td className="sticky left-0 bg-card px-3 py-1.5">
                                        <p className="font-medium">{user.name}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {user.email} &middot;{' '}
                                            <span className="capitalize">
                                                {user.role.replaceAll('_', ' ')}
                                            </span>
                                        </p>
                                    </td>
                                    {uploadTypes.map((type) => (
                                        <td key={type.id} className="px-3 py-1.5 text-center">
                                            <div className="flex items-center justify-center">
                                                {user.role === 'uploader' ? (
                                                    <Checkbox
                                                        checked={
                                                            selections[user.id]?.includes(
                                                                type.id,
                                                            ) ?? false
                                                        }
                                                        disabled={
                                                            !type.is_active ||
                                                            user.status !== 'active'
                                                        }
                                                        onCheckedChange={() =>
                                                            toggle(user.id, type.id)
                                                        }
                                                        aria-label={`${type.name} access for ${user.email}`}
                                                    />
                                                ) : (
                                                    <div className="flex h-5 w-5 items-center justify-center rounded bg-red-500 font-bold text-white text-xs">
                                                        X
                                                    </div>
                                                )}
                                            </div>
                                        </td>
                                    ))}
                                    <td className="px-3 py-1.5">
                                        <StatusBadge value={user.status} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <PaginationNav
                    currentPage={users.current_page}
                    lastPage={users.last_page}
                    previousUrl={users.prev_page_url}
                    nextUrl={users.next_page_url}
                    label="Upload access pages"
                />
            </PageShell>
        </>
    );
}

AccessIndex.layout = { breadcrumbs: [{ title: 'Upload access', href: '/admin/upload-access' }] };
