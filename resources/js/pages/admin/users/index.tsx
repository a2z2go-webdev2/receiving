import { Head, useForm } from '@inertiajs/react';
import { KeyRound, Pencil, Plus, UserCog } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { FlashMessage } from '@/components/receiving/flash-message';
import { PageShell } from '@/components/receiving/page-shell';
import { PaginationNav } from '@/components/receiving/pagination-nav';
import { StatusBadge } from '@/components/receiving/status-badge';
import { Button } from '@/components/ui/button';
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

type UserRow = {
    id: number;
    name: string;
    email: string;
    status: string;
    role: string;
    upload_types: string[];
    created_at: string;
};
type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
const initial = {
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    role: 'uploader',
    status: 'active',
};

export default function UsersIndex({ users }: { users: Paginator<UserRow> }) {
    const [adding, setAdding] = useState(false);
    const [editing, setEditing] = useState<UserRow | null>(null);
    const [resetting, setResetting] = useState<UserRow | null>(null);
    const create = useForm(initial);
    const edit = useForm({ name: '', email: '', role: 'uploader', status: 'active' });
    const password = useForm({ password: '', password_confirmation: '' });

    function openEdit(user: UserRow) {
        setEditing(user);
        edit.setData({ name: user.name, email: user.email, role: user.role, status: user.status });
        edit.clearErrors();
    }
    return (
        <>
            <Head title="User Management" />
            <PageShell
                title="User management"
                description="Create controlled accounts, assign a role, deactivate access, and set temporary passwords."
                actions={
                    <Button onClick={() => setAdding(true)}>
                        <Plus /> Add user
                    </Button>
                }
            >
                <FlashMessage />
                <div className="overflow-x-auto rounded-lg border bg-card">
                    <table className="w-full text-left text-xs">
                        <thead className="border-b bg-muted/50">
                            <tr>
                                <th className="px-3 py-1.5">User</th>
                                <th className="px-3 py-1.5">Role</th>
                                <th className="px-3 py-1.5">Status</th>
                                <th className="px-3 py-1.5">Receiving access</th>
                                <th className="px-3 py-1.5">Created</th>
                                <th className="px-3 py-1.5">
                                    <span className="sr-only">Actions</span>
                                </th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {users.data.map((user) => (
                                <tr key={user.id}>
                                    <td className="px-3 py-1.5">
                                        <p className="font-medium">{user.name}</p>
                                        <p className="text-muted-foreground text-xs">
                                            {user.email}
                                        </p>
                                    </td>
                                    <td className="px-3 py-1.5 capitalize">
                                        {user.role.replaceAll('_', ' ')}
                                    </td>
                                    <td className="px-3 py-1.5">
                                        <StatusBadge value={user.status} />
                                    </td>
                                    <td className="px-3 py-1.5 text-muted-foreground">
                                        {user.upload_types.length > 0
                                            ? user.upload_types.join(', ')
                                            : 'No lanes assigned'}
                                    </td>
                                    <td className="px-3 py-1.5 text-muted-foreground">
                                        {new Date(user.created_at).toLocaleDateString()}
                                    </td>
                                    <td className="px-3 py-1.5">
                                        <div className="flex justify-end gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => openEdit(user)}
                                            >
                                                <Pencil /> Edit
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => {
                                                    setResetting(user);
                                                    password.reset();
                                                    password.clearErrors();
                                                }}
                                            >
                                                <KeyRound /> Password
                                            </Button>
                                        </div>
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
                    label="User list pages"
                />
            </PageShell>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add user account</DialogTitle>
                        <DialogDescription>
                            Create an admin or uploader account with a temporary password.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            create.post('/admin/users', {
                                onSuccess: () => {
                                    setAdding(false);
                                    create.reset();
                                },
                            });
                        }}
                    >
                        <UserFields
                            data={create.data}
                            setData={create.setData}
                            errors={create.errors}
                            includePassword
                        />
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setAdding(false)}
                            >
                                Cancel
                            </Button>
                            <Button disabled={create.processing}>
                                <UserCog /> {create.processing ? 'Creating…' : 'Create user'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={editing !== null} onOpenChange={(open) => !open && setEditing(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Edit user account</DialogTitle>
                        <DialogDescription>
                            Changes to status and role take effect on the next authorized request.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (editing)
                                edit.put(`/admin/users/${editing.id}`, {
                                    onSuccess: () => setEditing(null),
                                });
                        }}
                    >
                        <UserFields data={edit.data} setData={edit.setData} errors={edit.errors} />
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setEditing(null)}
                            >
                                Cancel
                            </Button>
                            <Button disabled={edit.processing}>
                                {edit.processing ? 'Saving…' : 'Save changes'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={resetting !== null} onOpenChange={(open) => !open && setResetting(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Set temporary password</DialogTitle>
                        <DialogDescription>
                            Set a strong temporary password for {resetting?.email}. Share it through
                            an approved channel.
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-3"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (resetting)
                                password.put(`/admin/users/${resetting.id}/password`, {
                                    onSuccess: () => {
                                        setResetting(null);
                                        password.reset();
                                    },
                                });
                        }}
                    >
                        <div className="space-y-1.5">
                            <Label htmlFor="temporary-password">Temporary password</Label>
                            <Input
                                id="temporary-password"
                                type="password"
                                value={password.data.password}
                                onChange={(event) =>
                                    password.setData('password', event.target.value)
                                }
                            />
                            <InputError message={password.errors.password} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="temporary-password-confirmation">
                                Confirm password
                            </Label>
                            <Input
                                id="temporary-password-confirmation"
                                type="password"
                                value={password.data.password_confirmation}
                                onChange={(event) =>
                                    password.setData('password_confirmation', event.target.value)
                                }
                            />
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => setResetting(null)}
                            >
                                Cancel
                            </Button>
                            <Button disabled={password.processing}>
                                {password.processing ? 'Saving…' : 'Set password'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

type FieldData = {
    name: string;
    email: string;
    role: string;
    status: string;
    password?: string;
    password_confirmation?: string;
};
function UserFields({
    data,
    setData,
    errors,
    includePassword = false,
}: {
    data: FieldData;
    setData: (key: string, value: string) => void;
    errors: Partial<Record<keyof FieldData, string>>;
    includePassword?: boolean;
}) {
    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="user-name">Full name</Label>
                    <Input
                        id="user-name"
                        value={data.name}
                        onChange={(event) => setData('name', event.target.value)}
                    />
                    <InputError message={errors.name} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="user-email">Email address</Label>
                    <Input
                        id="user-email"
                        type="email"
                        value={data.email}
                        onChange={(event) => setData('email', event.target.value)}
                    />
                    <InputError message={errors.email} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="user-role">Role</Label>
                    <Select value={data.role} onValueChange={(value) => setData('role', value)}>
                        <SelectTrigger id="user-role" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="uploader">Uploader</SelectItem>
                            <SelectItem value="warehouse_operator">Warehouse operator</SelectItem>
                            <SelectItem value="driver">Driver</SelectItem>
                            <SelectItem value="admin">Admin</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.role} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="user-status">Status</Label>
                    <Select value={data.status} onValueChange={(value) => setData('status', value)}>
                        <SelectTrigger id="user-status" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="suspended">Suspended</SelectItem>
                            <SelectItem value="deactivated">Deactivated</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={errors.status} />
                </div>
            </div>
            {includePassword && (
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="user-password">Temporary password</Label>
                        <Input
                            id="user-password"
                            type="password"
                            value={data.password}
                            onChange={(event) => setData('password', event.target.value)}
                        />
                        <InputError message={errors.password} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="user-password-confirmation">Confirm password</Label>
                        <Input
                            id="user-password-confirmation"
                            type="password"
                            value={data.password_confirmation}
                            onChange={(event) =>
                                setData('password_confirmation', event.target.value)
                            }
                        />
                    </div>
                </div>
            )}
        </>
    );
}

UsersIndex.layout = { breadcrumbs: [{ title: 'Users', href: '/admin/users' }] };
