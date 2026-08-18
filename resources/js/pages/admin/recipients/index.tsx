import { Head, router, useForm } from '@inertiajs/react';
import { Inbox, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type UploadType = { id: number; name: string; recipient_count: number };
type Recipient = {
    id: number;
    upload_type_id: number;
    upload_type: string;
    email: string;
    type: string;
    is_active: boolean;
};
const empty = { upload_type_id: 0, email: '', type: 'to', is_active: true };
type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export default function RecipientsIndex({
    uploadTypes,
    recipients,
    activeUploadTypeId,
}: {
    uploadTypes: UploadType[];
    recipients: Paginator<Recipient>;
    activeUploadTypeId: number | null;
}) {
    const [activeTypeId, setActiveTypeId] = useState(activeUploadTypeId ?? uploadTypes[0]?.id ?? 0);
    const [open, setOpen] = useState(false);
    const [editing, setEditing] = useState<Recipient | null>(null);
    const [removing, setRemoving] = useState<Recipient | null>(null);
    const form = useForm(empty);
    function add() {
        setEditing(null);
        form.setData({ ...empty, upload_type_id: activeTypeId });
        form.clearErrors();
        setOpen(true);
    }
    function edit(recipient: Recipient) {
        setEditing(recipient);
        form.setData({
            upload_type_id: recipient.upload_type_id,
            email: recipient.email,
            type: recipient.type,
            is_active: recipient.is_active,
        });
        form.clearErrors();
        setOpen(true);
    }
    function submit() {
        const options = {
            onSuccess: () => {
                setActiveTypeId(form.data.upload_type_id);
                setOpen(false);
                form.reset();
            },
        };
        editing
            ? form.put(`/admin/recipients/${editing.id}`, options)
            : form.post('/admin/recipients', options);
    }

    function showUploadType(uploadTypeId: number) {
        setActiveTypeId(uploadTypeId);
        router.get(
            '/admin/recipients',
            { upload_type_id: uploadTypeId },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Email Recipients" />
            <PageShell
                title="Email recipients"
                description="Define To, CC, and BCC delivery separately for each receiving type."
                actions={
                    <Button onClick={add}>
                        <Plus /> Add recipient
                    </Button>
                }
            >
                <FlashMessage />
                <div className="rounded-xl border bg-card p-2 shadow-sm">
                    <div
                        className="flex w-full gap-2 overflow-x-auto p-1"
                        role="tablist"
                        aria-label="Upload types"
                    >
                        {uploadTypes.map((type) => {
                            const active = type.id === activeTypeId;
                            return (
                                <Button
                                    key={type.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    variant={active ? 'default' : 'ghost'}
                                    onClick={() => showUploadType(type.id)}
                                    className="h-auto shrink-0 justify-between rounded-lg px-4 py-3 sm:flex-1"
                                >
                                    <span className="flex items-center gap-2">
                                        <Inbox className="size-4" />
                                        <span className="text-left">
                                            <span className="block font-semibold">{type.name}</span>
                                            <span
                                                className={`block text-xs ${active ? 'text-primary-foreground/75' : 'text-muted-foreground'}`}
                                            >
                                                Notification recipients
                                            </span>
                                        </span>
                                    </span>
                                    <Badge variant={active ? 'secondary' : 'outline'}>
                                        {type.recipient_count}
                                    </Badge>
                                </Button>
                            );
                        })}
                    </div>
                </div>
                <div className="overflow-x-auto rounded-lg border bg-card">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="px-3 py-1.5">Recipient email</th>
                                <th className="px-3 py-1.5">Delivery</th>
                                <th className="px-3 py-1.5">Status</th>
                                <th className="px-3 py-1.5">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {recipients.data.map((recipient) => (
                                <tr key={recipient.id}>
                                    <td className="px-3 py-1.5">{recipient.email}</td>
                                    <td className="px-3 py-1.5 uppercase">{recipient.type}</td>
                                    <td className="px-3 py-1.5">
                                        <StatusBadge
                                            value={recipient.is_active ? 'active' : 'inactive'}
                                        />
                                    </td>
                                    <td className="px-3 py-1.5">
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => edit(recipient)}
                                            >
                                                <Pencil /> Edit
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => setRemoving(recipient)}
                                            >
                                                <Trash2 /> Remove
                                            </Button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                            {recipients.data.length === 0 && (
                                <tr>
                                    <td
                                        colSpan={4}
                                        className="px-3 py-8 text-center text-muted-foreground"
                                    >
                                        No recipients are configured for this upload type yet.
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <PaginationNav
                    currentPage={recipients.current_page}
                    lastPage={recipients.last_page}
                    previousUrl={recipients.prev_page_url}
                    nextUrl={recipients.next_page_url}
                    label="Email recipient pages"
                />
            </PageShell>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Edit recipient' : 'Add email recipient'}
                        </DialogTitle>
                        <DialogDescription>
                            Notification recipients are resolved at send time from active records.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="recipient-type">Receiving type</Label>
                            <Select
                                value={String(form.data.upload_type_id)}
                                onValueChange={(value) =>
                                    form.setData('upload_type_id', Number(value))
                                }
                            >
                                <SelectTrigger id="recipient-type" className="w-full">
                                    <SelectValue placeholder="Choose an upload type" />
                                </SelectTrigger>
                                <SelectContent>
                                    {uploadTypes.map((type) => (
                                        <SelectItem key={type.id} value={String(type.id)}>
                                            {type.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.upload_type_id} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="recipient-email">Email address</Label>
                            <Input
                                id="recipient-email"
                                type="email"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                            <InputError message={form.errors.email} />
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <div className="space-y-1.5">
                                <Label htmlFor="delivery-type">Email type</Label>
                                <Select
                                    value={form.data.type}
                                    onValueChange={(value) => form.setData('type', value)}
                                >
                                    <SelectTrigger id="delivery-type" className="w-full">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="to">To</SelectItem>
                                        <SelectItem value="cc">CC</SelectItem>
                                        <SelectItem value="bcc">BCC</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex h-9 items-center gap-2 self-end rounded-md border px-3">
                                <Checkbox
                                    id="recipient-active"
                                    checked={form.data.is_active}
                                    onCheckedChange={(checked) =>
                                        form.setData('is_active', checked === true)
                                    }
                                />
                                <Label htmlFor="recipient-active">Active</Label>
                            </div>
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button disabled={form.processing}>
                                {form.processing ? 'Saving…' : 'Save recipient'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <Dialog open={removing !== null} onOpenChange={(next) => !next && setRemoving(null)}>
                <DialogContent hideCloseButton>
                    <DialogHeader>
                        <DialogTitle>Remove this recipient?</DialogTitle>
                        <DialogDescription>
                            {removing?.email} will stop receiving notifications for{' '}
                            {removing?.upload_type}.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRemoving(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (!removing) return;
                                router.delete(`/admin/recipients/${removing.id}`, {
                                    onSuccess: () => setRemoving(null),
                                });
                            }}
                        >
                            <Trash2 /> Remove recipient
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

RecipientsIndex.layout = {
    breadcrumbs: [{ title: 'Email recipients', href: '/admin/recipients' }],
};
