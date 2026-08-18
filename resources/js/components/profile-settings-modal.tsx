import { Form } from '@inertiajs/react';
import { useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import InputError from '@/components/input-error';
import { PasswordChecklist } from '@/components/password-checklist';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import type { User } from '@/types';

export function ProfileSettingsModal({
    user,
    open,
    onOpenChange,
}: {
    user: User;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);
    const [newPassword, setNewPassword] = useState('');

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Profile settings</DialogTitle>
                    <DialogDescription>Update your name, email, and password.</DialogDescription>
                </DialogHeader>

                <div className="grid gap-6 py-2">
                    <Form
                        {...ProfileController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        onSuccess={() => {
                            onOpenChange(false);
                        }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <div className="space-y-3">
                                <h3 className="font-medium text-sm">Personal Information</h3>
                                <div className="grid gap-1">
                                    <Label htmlFor="modal_name">Name</Label>
                                    <Input
                                        id="modal_name"
                                        className="h-8 text-xs"
                                        defaultValue={user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                    />
                                    <InputError message={errors.name} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="modal_email">Email address</Label>
                                    <Input
                                        id="modal_email"
                                        type="email"
                                        className="h-8 text-xs"
                                        defaultValue={user.email}
                                        name="email"
                                        required
                                        autoComplete="username"
                                    />
                                    <InputError message={errors.email} />
                                </div>
                                <div>
                                    <Button disabled={processing} className="h-8 text-xs">
                                        Save Profile
                                    </Button>
                                </div>
                            </div>
                        )}
                    </Form>

                    <Separator />

                    <Form
                        {...SecurityController.update.form()}
                        options={{
                            preserveScroll: true,
                        }}
                        resetOnSuccess
                        onSuccess={() => {
                            setNewPassword('');
                            onOpenChange(false);
                        }}
                        resetOnError={['password', 'password_confirmation', 'current_password']}
                        onError={(errors) => {
                            if (errors.password) {
                                passwordInput.current?.focus();
                            }
                            if (errors.current_password) {
                                currentPasswordInput.current?.focus();
                            }
                        }}
                        className="space-y-4"
                    >
                        {({ errors, processing }) => (
                            <div className="space-y-3">
                                <h3 className="font-medium text-sm">Change Password</h3>
                                <div className="grid gap-1">
                                    <Label htmlFor="modal_current_password">Current password</Label>
                                    <PasswordInput
                                        id="modal_current_password"
                                        ref={currentPasswordInput}
                                        name="current_password"
                                        className="h-8 text-xs"
                                        autoComplete="current-password"
                                    />
                                    <InputError message={errors.current_password} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="modal_password">New password</Label>
                                    <PasswordInput
                                        id="modal_password"
                                        ref={passwordInput}
                                        name="password"
                                        className="h-8 text-xs"
                                        autoComplete="new-password"
                                        onChange={(e) => setNewPassword(e.target.value)}
                                    />
                                    <PasswordChecklist password={newPassword} className="mt-1" />
                                    <InputError message={errors.password} />
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="modal_password_confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="modal_password_confirmation"
                                        name="password_confirmation"
                                        className="h-8 text-xs"
                                        autoComplete="new-password"
                                    />
                                    <InputError message={errors.password_confirmation} />
                                </div>
                                <div>
                                    <Button disabled={processing} className="h-8 text-xs">
                                        Update Password
                                    </Button>
                                </div>
                            </div>
                        )}
                    </Form>
                </div>
            </DialogContent>
        </Dialog>
    );
}
