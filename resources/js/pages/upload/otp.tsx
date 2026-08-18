import { Head, useForm } from '@inertiajs/react';
import { KeyRound, Mail } from 'lucide-react';
import InputError from '@/components/input-error';
import { PageShell } from '@/components/receiving/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { InputOTP, InputOTPGroup, InputOTPSlot } from '@/components/ui/input-otp';
import { Label } from '@/components/ui/label';

export default function UploadOtp({
    uploadType,
    maskedEmail,
    expiresMinutes,
}: {
    uploadType: { name: string; slug: string };
    maskedEmail: string;
    expiresMinutes: number;
}) {
    const form = useForm({ code: '', remember: false });
    const resend = useForm({});

    return (
        <>
            <Head title={`Verify ${uploadType.name} access`} />
            <PageShell
                title={`${uploadType.name} receiving`}
                description="One final security check before this upload lane opens."
            >
                <Card className="mx-auto w-full max-w-lg">
                    <CardHeader className="text-center">
                        <div className="mx-auto mb-2 flex size-12 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <KeyRound className="size-6" />
                        </div>
                        <CardTitle>Email verification code</CardTitle>
                        <CardDescription className="flex items-center justify-center gap-1">
                            <Mail className="size-4" /> We sent a 6-digit code to {maskedEmail}. It
                            expires in {expiresMinutes} minutes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(`/upload/${uploadType.slug}/otp/verify`);
                            }}
                            className="space-y-5"
                        >
                            <div className="flex justify-center">
                                <InputOTP
                                    maxLength={6}
                                    value={form.data.code}
                                    onChange={(code) => form.setData('code', code)}
                                    autoFocus
                                    aria-label="Six digit verification code"
                                >
                                    <InputOTPGroup>
                                        {[0, 1, 2, 3, 4, 5].map((index) => (
                                            <InputOTPSlot key={index} index={index} />
                                        ))}
                                    </InputOTPGroup>
                                </InputOTP>
                            </div>
                            <InputError message={form.errors.code} className="text-center" />
                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    checked={form.data.remember}
                                    onCheckedChange={(checked) =>
                                        form.setData('remember', checked === true)
                                    }
                                />
                                <Label htmlFor="remember" className="font-normal text-sm">
                                    Remember this device for 30 days
                                </Label>
                            </div>
                            <Button
                                type="submit"
                                className="h-11 w-full"
                                disabled={form.processing || form.data.code.length !== 6}
                            >
                                {form.processing ? 'Verifying…' : 'Verify and continue'}
                            </Button>
                        </form>
                        <div className="mt-5 border-t pt-5 text-center">
                            <p className="mb-2 text-muted-foreground text-sm">
                                Didn't receive the code?
                            </p>
                            <Button
                                variant="ghost"
                                disabled={resend.processing}
                                onClick={() => resend.post(`/upload/${uploadType.slug}/otp/resend`)}
                            >
                                {resend.processing ? 'Sending…' : 'Send a new code'}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </PageShell>
        </>
    );
}

UploadOtp.layout = { breadcrumbs: [{ title: 'Receiving verification', href: '#' }] };
