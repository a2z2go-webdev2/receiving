import { useForm } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
import { type FormEventHandler, useState } from 'react';
import Heading from '@/components/heading';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

export default function FactoryReset() {
    const [isResetDialogOpen, setIsResetDialogOpen] = useState(false);

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
        clearErrors,
    } = useForm({
        confirmation: '',
        password: '',
    });

    const submitReset: FormEventHandler = (e) => {
        e.preventDefault();
        destroy('/admin/system-reset', {
            preserveScroll: true,
            onSuccess: () => {
                setIsResetDialogOpen(false);
                reset();
                clearErrors();
            },
        });
    };

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="Factory Reset"
                description="Restore the system to a clean state"
            />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">Danger Zone</p>
                    <p className="text-sm">
                        Permanently delete all uploads, files, settings, and non-admin users.
                    </p>
                </div>

                <Dialog
                    open={isResetDialogOpen}
                    onOpenChange={(open) => {
                        setIsResetDialogOpen(open);
                        if (!open) {
                            reset();
                            clearErrors();
                        }
                    }}
                >
                    <DialogTrigger asChild>
                        <Button variant="destructive">Reset system</Button>
                    </DialogTrigger>
                    <DialogContent hideCloseButton>
                        <form onSubmit={submitReset}>
                            <DialogHeader>
                                <DialogTitle>Are you absolutely sure?</DialogTitle>
                                <DialogDescription>
                                    This action cannot be undone. This will permanently delete your
                                    database records and purge all files in the R2 bucket.
                                </DialogDescription>
                            </DialogHeader>

                            <Alert variant="destructive" className="mt-4 mb-6">
                                <AlertTriangle />
                                <AlertTitle>Warning</AlertTitle>
                                <AlertDescription>
                                    Your administrator account, saved item records,
                                    roles/permissions, and required upload lane configurations will
                                    be preserved. Everything else will be wiped.
                                </AlertDescription>
                            </Alert>

                            <div className="space-y-4">
                                <div className="space-y-2">
                                    <Label htmlFor="reset-confirmation">
                                        Type{' '}
                                        <span className="select-all font-mono font-semibold">
                                            RESET SYSTEM
                                        </span>{' '}
                                        to confirm
                                    </Label>
                                    <Input
                                        id="reset-confirmation"
                                        value={data.confirmation}
                                        onChange={(e) => setData('confirmation', e.target.value)}
                                        placeholder="RESET SYSTEM"
                                        autoComplete="off"
                                    />
                                    {errors.confirmation && (
                                        <p className="text-destructive text-sm">
                                            {errors.confirmation}
                                        </p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="reset-password">Administrator Password</Label>
                                    <Input
                                        id="reset-password"
                                        type="password"
                                        value={data.password}
                                        onChange={(e) => setData('password', e.target.value)}
                                        placeholder="Enter your password to confirm"
                                        autoComplete="current-password"
                                    />
                                    {errors.password && (
                                        <p className="text-destructive text-sm">
                                            {errors.password}
                                        </p>
                                    )}
                                </div>
                            </div>

                            <DialogFooter className="mt-6">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setIsResetDialogOpen(false);
                                        reset();
                                        clearErrors();
                                    }}
                                >
                                    Cancel
                                </Button>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={
                                        processing ||
                                        data.confirmation !== 'RESET SYSTEM' ||
                                        !data.password
                                    }
                                >
                                    {processing ? 'Resetting...' : 'Factory reset'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
