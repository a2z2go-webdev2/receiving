import { Head } from '@inertiajs/react';
import {
    CheckCircle2,
    File,
    FileUp,
    LoaderCircle,
    LocateFixed,
    MapPin,
    UploadCloud,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { PageShell } from '@/components/receiving/page-shell';
import { announceSessionExpired } from '@/components/session-expired-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { csrfToken } from '@/lib/csrf';

type UploadTarget = {
    url: string;
    headers: Record<string, string>;
};

type InitResponse = {
    upload_id: number;
    serial_number: number;
    files: (UploadTarget & {
        id: number;
        name: string;
        fallback: UploadTarget | null;
    })[];
};

type UploadLocation = {
    latitude: number;
    longitude: number;
    accuracy: number;
    captured_at: string;
};

export default function UploadPage({
    uploadType,
    constraints,
}: {
    uploadType: { id: number; name: string; slug: string; workflow: string };
    constraints: {
        maxFiles: number;
        maxFileKilobytes: number;
        allowedExtensions: string[];
        maxLocationAccuracyMeters: number;
    };
}) {
    const isPurchaseOrder = uploadType.workflow === 'purchase_order';
    const effectiveMaxFiles = isPurchaseOrder ? 1 : constraints.maxFiles;
    const hideStandardLaneHeader = ['a2z2go', 'pingcon', 'bonita', 'keysys'].includes(
        uploadType.slug,
    );
    const acceptedFileTypes = constraints.allowedExtensions
        .map((extension) => `.${extension}`)
        .join(',');
    const maxFileMegabytes = constraints.maxFileKilobytes / 1024;
    const maxFileSizeLabel = Number.isInteger(maxFileMegabytes)
        ? maxFileMegabytes.toFixed(0)
        : maxFileMegabytes.toFixed(1);
    const inputRef = useRef<HTMLInputElement>(null);
    const submissionId = useRef(crypto.randomUUID());
    const [files, setFiles] = useState<File[]>([]);
    const [confirming, setConfirming] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [currentFile, setCurrentFile] = useState('');
    const [progress, setProgress] = useState(0);
    const [error, setError] = useState('');
    const [success, setSuccess] = useState('');
    const [location, setLocation] = useState<UploadLocation | null>(null);
    const [showLocationModal, setShowLocationModal] = useState(() => {
        return sessionStorage.getItem('locationModalSeen') !== 'true';
    });
    const [locationError, setLocationError] = useState('');
    const [locating, setLocating] = useState(false);
    const totalBytes = useMemo(() => files.reduce((sum, file) => sum + file.size, 0), [files]);

    function selectFiles(selected: FileList | null) {
        setError('');
        setSuccess('');
        const next = Array.from(selected ?? []);
        if (next.length === 0) return;
        if (next.length > effectiveMaxFiles) {
            setError(
                `Choose no more than ${effectiveMaxFiles} file${effectiveMaxFiles === 1 ? '' : 's'} per upload.`,
            );
            return;
        }
        const invalid = next.find((file) => {
            const extension = file.name.split('.').pop()?.toLowerCase() ?? '';
            return (
                !constraints.allowedExtensions.includes(extension) ||
                file.size < 1 ||
                file.size > constraints.maxFileKilobytes * 1024
            );
        });
        if (invalid) {
            setError(
                `${invalid.name} is unsupported, empty, or larger than ${maxFileSizeLabel} MB.`,
            );
            return;
        }
        setFiles(next);
    }

    async function submit() {
        setConfirming(false);
        setProcessing(true);
        setProgress(0);
        setError('');
        const uploaded = new Array(files.length).fill(0) as number[];

        try {
            let currentLocation = location;
            if (!currentLocation) {
                try {
                    currentLocation = await captureLocation();
                } catch {
                    // Proceed without location if capture fails
                }
            }

            const initiation = await fetch(`/upload/${uploadType.slug}/transactions`, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    submission_id: submissionId.current,
                    location: currentLocation,
                    files: files.map((file) => ({
                        name: file.name,
                        size: file.size,
                        content_type: file.type || contentTypeFor(file.name),
                        extension: file.name.split('.').pop()?.toLowerCase(),
                    })),
                }),
            });
            if (!initiation.ok) throw new Error(await responseMessage(initiation));
            const transaction = (await initiation.json()) as InitResponse;

            for (let index = 0; index < files.length; index += 1) {
                setCurrentFile(files[index].name);
                await putFileWithFallback(transaction.files[index], files[index], (loaded) => {
                    uploaded[index] = loaded;
                    setProgress(
                        totalBytes === 0
                            ? 0
                            : Math.round(
                                  (uploaded.reduce((sum, value) => sum + value, 0) / totalBytes) *
                                      100,
                              ),
                    );
                });
            }

            const completion = await fetch(
                `/upload/${uploadType.slug}/transactions/${transaction.upload_id}/complete`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
                },
            );
            if (!completion.ok) throw new Error(await responseMessage(completion));
            const result = (await completion.json()) as { message: string };
            setSuccess(result.message);
            submissionId.current = crypto.randomUUID();
            setFiles([]);
            if (inputRef.current) inputRef.current.value = '';
        } catch (reason) {
            setError(
                reason instanceof Error
                    ? reason.message
                    : 'Upload failed. Please try again or contact the system administrator.',
            );
        } finally {
            setProcessing(false);
            setCurrentFile('');
        }
    }

    async function captureLocation(): Promise<UploadLocation> {
        setLocating(true);
        setLocationError('');

        try {
            if (!('geolocation' in navigator)) {
                throw new Error(
                    'This browser does not support location access. Use a current browser on a device with location services.',
                );
            }

            const position = await currentPosition();
            if (position.coords.accuracy > constraints.maxLocationAccuracyMeters) {
                throw new Error(
                    `This location is only accurate to about ${Math.round(position.coords.accuracy)} m. The maximum accepted range is ${Math.round(constraints.maxLocationAccuracyMeters)} m. Check Location Services and try again.`,
                );
            }

            const reading = {
                latitude: position.coords.latitude,
                longitude: position.coords.longitude,
                accuracy: position.coords.accuracy,
                captured_at: new Date(position.timestamp).toISOString(),
            };
            setLocation(reading);

            return reading;
        } catch (reason) {
            const message = locationMessage(reason);
            setLocation(null);
            setLocationError(message);
            throw new Error(message);
        } finally {
            setLocating(false);
        }
    }

    async function allowLocation() {
        sessionStorage.setItem('locationModalSeen', 'true');
        setShowLocationModal(false);
        try {
            await captureLocation();
        } catch {
            // Errors are handled internally; uploads still proceed without location
        }
    }

    function skipLocation() {
        sessionStorage.setItem('locationModalSeen', 'true');
        setShowLocationModal(false);
        setLocationError('');
    }

    return (
        <>
            <Head
                title={isPurchaseOrder ? 'Purchase Order Upload' : `${uploadType.name} Receiving`}
            />
            <PageShell
                title={isPurchaseOrder ? 'Upload purchase orders' : `${uploadType.name} receiving`}
                description={
                    isPurchaseOrder
                        ? 'Upload Purchase Order PDFs for secure AI extraction. No email notification or review link will be created.'
                        : 'Upload one complete document set. We will securely process the files, extract the data, and send the review notification in the background.'
                }
                hideHeader={hideStandardLaneHeader}
            >
                <div className="mx-auto w-full max-w-3xl space-y-4">
                    {success && (
                        <div
                            role="status"
                            className="flex gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-900"
                        >
                            <CheckCircle2 className="mt-0.5 size-5 shrink-0" />
                            <p className="text-sm">{success}</p>
                        </div>
                    )}
                    {error && (
                        <div
                            role="alert"
                            className="rounded-xl border border-red-200 bg-red-50 p-4 text-red-900"
                        >
                            <p className="font-medium">We couldn't complete that upload.</p>
                            <p className="mt-1 text-sm">{error}</p>
                        </div>
                    )}

                    <Card className="shadow-sm">
                        <CardHeader>
                            <CardTitle>Choose files to upload</CardTitle>
                            <CardDescription>
                                {constraints.allowedExtensions
                                    .map((extension) => extension.toUpperCase())
                                    .join(', ')}{' '}
                                ·{' '}
                                {effectiveMaxFiles === 1
                                    ? '1 file at a time'
                                    : `up to ${effectiveMaxFiles} files`}{' '}
                                · {maxFileSizeLabel} MB each
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {location && (
                                <div className="flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-emerald-900">
                                    <MapPin className="size-5 shrink-0" />
                                    <div>
                                        <p className="font-medium text-sm">Location ready</p>
                                        <p className="text-xs">
                                            Reported accuracy: about {Math.round(location.accuracy)}{' '}
                                            m
                                        </p>
                                    </div>
                                </div>
                            )}
                            <label className="flex min-h-48 cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-primary/25 border-dashed bg-primary/[0.03] p-6 text-center transition focus-within:ring-2 focus-within:ring-primary hover:border-primary/60 hover:bg-primary/[0.06]">
                                <div className="mb-3 rounded-full bg-primary/10 p-3 text-primary">
                                    <FileUp className="size-7" />
                                </div>
                                <span className="font-medium">
                                    {effectiveMaxFiles === 1
                                        ? 'Choose a scanned file'
                                        : 'Choose scanned files'}
                                </span>
                                <span className="mt-1 text-muted-foreground text-sm">
                                    {effectiveMaxFiles === 1
                                        ? 'Select the document for this receiving transaction.'
                                        : 'Select all documents for this receiving transaction together.'}
                                </span>
                                <input
                                    ref={inputRef}
                                    type="file"
                                    className="sr-only"
                                    multiple={effectiveMaxFiles > 1}
                                    accept={acceptedFileTypes}
                                    onChange={(event) => selectFiles(event.target.files)}
                                    disabled={processing}
                                />
                            </label>

                            {files.length > 0 && (
                                <ul className="grid gap-3 sm:grid-cols-2">
                                    {files.map((file, index) => {
                                        const isCurrent = processing && currentFile === file.name;
                                        return (
                                            <li
                                                key={`${file.name}-${file.size}`}
                                                className="flex flex-col justify-center gap-2 rounded-xl border bg-card p-3 shadow-sm"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <File className="size-5 shrink-0 text-primary" />
                                                    <div className="min-w-0 flex-1">
                                                        <p className="truncate font-medium text-sm">
                                                            {file.name}
                                                        </p>
                                                        <p className="text-muted-foreground text-xs">
                                                            {formatBytes(file.size)}
                                                        </p>
                                                    </div>
                                                    {!processing && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            className="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                                            onClick={() => {
                                                                const newFiles = files.filter(
                                                                    (_, i) => i !== index,
                                                                );
                                                                setFiles(newFiles);
                                                                if (
                                                                    newFiles.length === 0 &&
                                                                    inputRef.current
                                                                ) {
                                                                    inputRef.current.value = '';
                                                                }
                                                            }}
                                                        >
                                                            <X className="size-4" />
                                                        </Button>
                                                    )}
                                                    {isCurrent && (
                                                        <LoaderCircle className="size-4 shrink-0 animate-spin text-primary" />
                                                    )}
                                                </div>
                                                {isCurrent && (
                                                    <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className="h-full rounded-full bg-primary transition-[width]"
                                                            style={{ width: `${progress}%` }}
                                                        />
                                                    </div>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}

                            <div className="flex justify-end">
                                <Button
                                    size="lg"
                                    disabled={files.length === 0 || processing}
                                    onClick={() => setConfirming(true)}
                                >
                                    <UploadCloud /> Review and upload
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </PageShell>

            <Dialog
                open={showLocationModal}
                onOpenChange={(open) => {
                    if (!open) {
                        skipLocation();
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <div className="mb-1 flex size-11 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <LocateFixed className="size-6" />
                        </div>
                        <DialogTitle>Share your location for this upload?</DialogTitle>
                        <DialogDescription>
                            Sharing your location helps record where this receiving transaction was
                            uploaded. It is optional — you can still upload whether you allow or
                            skip it.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="rounded-lg bg-muted/50 p-3 text-sm">
                        <p className="font-medium">If you choose to share</p>
                        <ul className="mt-1 list-inside list-disc space-y-1 text-muted-foreground">
                            <li>Turn on Location Services or GPS for this device.</li>
                            <li>Allow location access when your browser asks.</li>
                            <li>Move near a window or outdoors if accuracy is low.</li>
                        </ul>
                    </div>
                    {locationError && (
                        <p
                            role="alert"
                            className="rounded-lg border border-red-200 bg-red-50 p-3 text-red-900 text-sm"
                        >
                            {locationError}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            className="w-full sm:w-auto"
                            disabled={locating}
                            onClick={allowLocation}
                        >
                            {locating ? <LoaderCircle className="animate-spin" /> : <MapPin />}
                            {locating ? 'Finding your location...' : 'Allow location'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent hideCloseButton>
                    <DialogHeader>
                        <DialogTitle>Confirm document upload</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to upload these{' '}
                            {isPurchaseOrder ? 'Purchase Order PDFs' : 'scanned documents'} to{' '}
                            {uploadType.name}
                            {isPurchaseOrder ? '' : ' Receiving'}?
                        </DialogDescription>
                    </DialogHeader>
                    <div className="min-w-0 overflow-hidden rounded-lg bg-muted/50 p-3 text-sm">
                        <p>
                            <strong>{files.length}</strong> file{files.length === 1 ? '' : 's'} ·{' '}
                            {formatBytes(totalBytes)}
                        </p>
                        <ul className="mt-2 flex max-h-48 flex-col gap-1 overflow-y-auto pr-1 text-muted-foreground">
                            {files.map((file) => (
                                <li key={file.name} className="flex min-w-0 items-start gap-2">
                                    <span
                                        aria-hidden
                                        className="mt-2 size-1.5 shrink-0 rounded-full bg-current"
                                    />
                                    <span className="min-w-0 flex-1 break-all">{file.name}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button onClick={submit}>Confirm upload</Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function currentPosition(): Promise<GeolocationPosition> {
    return new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(resolve, reject, {
            enableHighAccuracy: true,
            maximumAge: 0,
            timeout: 20_000,
        });
    });
}

function locationMessage(reason: unknown): string {
    if (typeof reason === 'object' && reason !== null && 'code' in reason) {
        const geolocationError = reason as GeolocationPositionError;
        if (geolocationError.code === geolocationError.PERMISSION_DENIED) {
            return 'Location permission is blocked. Allow location for this site in your browser settings, turn on device Location Services, then try again.';
        }
        if (geolocationError.code === geolocationError.TIMEOUT) {
            return 'We could not get an accurate location in time. Check that GPS is on, move near a window or outdoors, then try again.';
        }

        return 'Your device could not determine its location. Turn on Location Services or GPS, then try again.';
    }

    return reason instanceof Error
        ? reason.message
        : 'We could not access your location. Check your browser and device settings, then try again.';
}

async function putFileWithFallback(
    target: InitResponse['files'][number],
    file: File,
    onProgress: (loaded: number) => void,
): Promise<void> {
    try {
        await putFile(target, file, onProgress);
    } catch (primaryError) {
        if (target.fallback === null) throw primaryError;

        onProgress(0);
        try {
            await putFile(target.fallback, file, onProgress);
        } catch {
            throw new Error(
                `${file.name} could not be uploaded after retrying through the secure application connection.`,
            );
        }
    }
}

function putFile(
    target: UploadTarget,
    file: File,
    onProgress: (loaded: number) => void,
): Promise<void> {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        const isSameOrigin =
            target.url.startsWith(window.location.origin) || target.url.startsWith('/');
        request.open('PUT', target.url);
        Object.entries(target.headers).forEach(([name, value]) => {
            if (name.toLowerCase() !== 'host') {
                request.setRequestHeader(name, value);
            }
        });
        if (isSameOrigin) {
            request.setRequestHeader('X-XSRF-TOKEN', csrfToken());
        }
        request.withCredentials = isSameOrigin;
        request.upload.onprogress = (event) => onProgress(event.loaded);
        request.onerror = () => reject(new Error(`Network error while uploading ${file.name}.`));
        request.onload = () =>
            request.status >= 200 && request.status < 300
                ? resolve()
                : reject(new Error(`${file.name} could not be uploaded to secure staging.`));
        request.send(file);
    });
}

async function responseMessage(response: globalThis.Response): Promise<string> {
    const body = (await response.json().catch(() => null)) as {
        message?: string;
        errors?: Record<string, string[]>;
    } | null;
    const message =
        body?.message ??
        Object.values(body?.errors ?? {}).flat()[0] ??
        'The server could not complete the request.';
    if (response.status === 401 || response.status === 419) {
        announceSessionExpired(message);
    }

    return message;
}

function formatBytes(bytes: number): string {
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function contentTypeFor(name: string): string {
    const extension = name.split('.').pop()?.toLowerCase();
    if (extension === 'pdf') return 'application/pdf';
    if (extension === 'png') return 'image/png';
    return 'image/jpeg';
}
