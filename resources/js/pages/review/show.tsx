import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    Eye,
    FileText,
    Maximize,
    Plus,
    ShieldCheck,
    Trash2,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { TransformComponent, TransformWrapper } from 'react-zoom-pan-pinch';
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

type ExtractedField = { label: string; value: string };
type ExtractedItem = Record<string, string>;
type ReviewData = {
    document_type: string;
    fields: ExtractedField[];
    items: ExtractedItem[];
    _warnings?: string[];
};
type DraftField = ExtractedField & { _key: string; _isCustom?: boolean };
type DraftItem = ExtractedItem & { _key: string };
type ReviewDraft = {
    document_type: string;
    fields: DraftField[];
    items: DraftItem[];
    _warnings?: string[];
};
type ReviewFile = {
    id: number;
    name: string;
    content_type: string;
    preview_url: string;
    extraction: {
        id: number;
        document_type: string;
        corrected_data: ReviewData;
        review_status: string;
    };
};
type Upload = {
    serial_number: number;
    upload_type: string;
    uploader_email: string;
    created_at: string;
    review_status: string;
    files: ReviewFile[];
};

const defaultItemColumns = [
    'itemCode',
    'description',
    'package',
    'quantity',
    'unit',
    'unitPrice',
    'lineTotal',
    'amount',
];

export default function ReviewPage({ token, upload }: { token: string; upload: Upload }) {
    const [selectedIndex, setSelectedIndex] = useState(0);
    const [drafts, setDrafts] = useState<Record<number, ReviewDraft>>(() =>
        Object.fromEntries(
            upload.files.map((file) => [file.id, createDraft(file.extraction.corrected_data)]),
        ),
    );
    const [verifying, setVerifying] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const [previewOpen, setPreviewOpen] = useState(false);
    const [error, setError] = useState('');
    const selected = upload.files[selectedIndex];
    const draft = drafts[selected?.id];

    function selectFile(index: number) {
        if (index < 0 || index >= upload.files.length) return;
        setSelectedIndex(index);
        setError('');
    }

    function updateDraft(update: (current: ReviewDraft) => ReviewDraft) {
        if (!selected || !draft) return;
        setDrafts((current) => ({ ...current, [selected.id]: update(current[selected.id]) }));
    }

    function verifyAllFiles() {
        const correctedData = Object.fromEntries(
            upload.files.map((file) => [file.extraction.id, serializeDraft(drafts[file.id])]),
        );

        setVerifying(true);
        setError('');
        router.post(
            `/review/${token}/verify`,
            { corrected_data: correctedData as FormDataConvertible },
            {
                onError: (errors) => {
                    setConfirming(false);
                    setError(
                        Object.values(errors)[0] ??
                            'The reviewed data could not be saved. Check every field and try again.',
                    );
                },
                onFinish: () => setVerifying(false),
            },
        );
    }

    if (!selected || !draft) {
        return (
            <div className="flex min-h-screen items-center justify-center bg-slate-50 p-6 text-slate-900">
                No extracted files are available for review.
            </div>
        );
    }

    return (
        <div className="min-h-screen bg-slate-50 text-slate-950 lg:h-screen lg:overflow-hidden">
            <Head title={`Review SN-${upload.serial_number}`} />
            <header className="border-b bg-white lg:h-[64px]">
                <div className="mx-auto flex h-full max-w-[1920px] flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between lg:px-6 lg:py-0">
                    <div className="flex items-center gap-3">
                        <div className="flex size-10 items-center justify-center rounded-xl bg-primary text-primary-foreground">
                            <ShieldCheck className="size-5" />
                        </div>
                        <div>
                            <p className="font-semibold">Receiving Operations</p>
                            <p className="text-slate-500 text-xs">
                                Check and correct extracted data
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-col sm:items-end">
                        <h1 className="font-semibold text-base sm:text-lg">
                            Review SN-{upload.serial_number}
                        </h1>
                        <p className="mt-0.5 text-slate-500 text-xs">
                            {upload.upload_type} · {upload.uploader_email} ·{' '}
                            {new Date(upload.created_at).toLocaleString()}
                        </p>
                    </div>
                </div>
            </header>

            <main className="mx-auto max-w-[1920px] p-3 pb-24 md:p-4 lg:flex lg:h-[calc(100dvh-64px)] lg:flex-col lg:overflow-hidden lg:p-5">
                {error && (
                    <div
                        role="alert"
                        className="mb-3 shrink-0 rounded-lg border border-red-200 bg-red-50 p-3 text-red-900 text-sm"
                    >
                        {error}
                    </div>
                )}

                <div className="grid gap-4 lg:min-h-0 lg:flex-1 lg:grid-cols-[minmax(0,0.9fr)_minmax(500px,1.2fr)]">
                    <section
                        className="hidden min-h-0 flex-col overflow-hidden rounded-xl border bg-white shadow-sm lg:flex"
                        aria-label="Document preview"
                    >
                        <DocumentHeader
                            selected={selected}
                            selectedIndex={selectedIndex}
                            fileCount={upload.files.length}
                            onPrevious={() => selectFile(selectedIndex - 1)}
                            onNext={() => selectFile(selectedIndex + 1)}
                        />
                        <div className="min-h-0 flex-1 bg-slate-100 p-2">
                            <DocumentPreview file={selected} />
                        </div>
                    </section>

                    <section
                        className="flex min-h-[720px] flex-col overflow-hidden rounded-xl border bg-white shadow-sm lg:min-h-0"
                        aria-label="Extracted data editor"
                    >
                        <div className="shrink-0 border-b p-4">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="font-semibold text-lg">Extracted data</h2>
                                    <p className="text-slate-500 text-sm">
                                        Every label and value can be corrected.
                                    </p>
                                </div>
                                <StatusBadge value={selected.extraction.review_status} />
                            </div>
                            <div className="mt-3 lg:hidden">
                                <FileNavigator
                                    selectedIndex={selectedIndex}
                                    fileCount={upload.files.length}
                                    onPrevious={() => selectFile(selectedIndex - 1)}
                                    onNext={() => selectFile(selectedIndex + 1)}
                                />
                                <p className="mt-2 truncate font-medium text-sm">{selected.name}</p>
                            </div>
                        </div>

                        <div className="min-h-0 flex-1 space-y-6 overflow-y-auto p-4 md:p-5">
                            {draft._warnings?.map((warning) => (
                                <div
                                    key={warning}
                                    className="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-950 text-sm"
                                >
                                    <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                                    {warning}
                                </div>
                            ))}

                            <div className="space-y-1.5 rounded-lg border border-blue-100 bg-blue-50/30 p-3">
                                <Label
                                    htmlFor={`document-type-${selected.id}`}
                                    className="text-blue-900"
                                >
                                    Document type
                                </Label>
                                <Input
                                    id={`document-type-${selected.id}`}
                                    value={draft.document_type}
                                    onChange={(event) =>
                                        updateDraft((current) => ({
                                            ...current,
                                            document_type: event.target.value,
                                        }))
                                    }
                                    className="h-9 border-blue-200 bg-white font-medium"
                                />
                            </div>

                            <EditableFields
                                fileId={selected.id}
                                fields={draft.fields}
                                onChange={(fields) =>
                                    updateDraft((current) => ({ ...current, fields }))
                                }
                            />

                            <EditableItems
                                fileId={selected.id}
                                items={draft.items}
                                onChange={(items) =>
                                    updateDraft((current) => ({ ...current, items }))
                                }
                            />
                        </div>

                        <div className="flex shrink-0 justify-end border-t bg-slate-50 p-4">
                            <Button
                                className="h-11 px-5"
                                onClick={() => setConfirming(true)}
                                disabled={verifying}
                            >
                                <ShieldCheck /> Review and finish
                            </Button>
                        </div>
                    </section>
                </div>
            </main>

            <Button
                type="button"
                size="lg"
                className="fixed right-4 bottom-4 z-40 h-12 rounded-full px-5 shadow-xl lg:hidden"
                onClick={() => setPreviewOpen(true)}
                aria-label={`View scanned file ${selected.name}`}
            >
                <Eye /> View scanned file
            </Button>

            <Dialog open={previewOpen} onOpenChange={setPreviewOpen}>
                <DialogContent className="flex h-[calc(100dvh-1rem)] max-w-[calc(100%-1rem)] grid-rows-none flex-col gap-0 overflow-hidden p-0 sm:max-w-[calc(100%-1rem)]">
                    <DialogHeader className="shrink-0 border-b p-4 pr-12 text-left">
                        <DialogTitle>Scanned file</DialogTitle>
                        <DialogDescription className="truncate">{selected.name}</DialogDescription>
                    </DialogHeader>
                    <div className="shrink-0 border-b p-3">
                        <FileNavigator
                            selectedIndex={selectedIndex}
                            fileCount={upload.files.length}
                            onPrevious={() => selectFile(selectedIndex - 1)}
                            onNext={() => selectFile(selectedIndex + 1)}
                        />
                    </div>
                    <div className="min-h-0 flex-1 bg-slate-100 p-2">
                        <DocumentPreview file={selected} />
                    </div>
                </DialogContent>
            </Dialog>

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent hideCloseButton>
                    <DialogHeader>
                        <DialogTitle>Confirm the reviewed data</DialogTitle>
                        <DialogDescription>
                            Have you compared the data with all {upload.files.length} scanned{' '}
                            {upload.files.length === 1 ? 'file' : 'files'}?
                        </DialogDescription>
                    </DialogHeader>
                    <div className="flex gap-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-amber-950">
                        <AlertTriangle className="mt-0.5 size-5 shrink-0" />
                        <p className="text-sm">
                            Finishing saves every change on this page and closes this review link.
                        </p>
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setConfirming(false)}
                            disabled={verifying}
                        >
                            Go back and check
                        </Button>
                        <Button onClick={verifyAllFiles} disabled={verifying}>
                            <ShieldCheck /> {verifying ? 'Saving…' : 'Verify and finish'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

function EditableFields({
    fileId,
    fields,
    onChange,
}: {
    fileId: number;
    fields: DraftField[];
    onChange: (fields: DraftField[]) => void;
}) {
    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h3 className="font-semibold">Fields</h3>
                    <p className="text-slate-500 text-xs">
                        Edit labels or values the AI got wrong.
                    </p>
                </div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={() =>
                        onChange([
                            ...fields,
                            { _key: uniqueKey(), label: '', value: '', _isCustom: true },
                        ])
                    }
                >
                    <Plus /> Add field
                </Button>
            </div>
            {fields.length > 0 && (
                <div className="flex px-3 pb-1 font-medium text-slate-500 text-xs">
                    <div className="flex-1">Field</div>
                    <div className="flex-[1.5]">Value</div>
                    <div className="w-8" />
                </div>
            )}
            <div className="space-y-1.5">
                {fields.map((field, index) => (
                    <div
                        key={field._key}
                        className="flex items-center gap-2 rounded-md border bg-white p-1 shadow-sm transition-colors focus-within:border-blue-300 focus-within:ring-1 focus-within:ring-blue-300"
                    >
                        <div className="flex-1">
                            <Label htmlFor={`${fileId}-field-label-${index}`} className="sr-only">
                                Field
                            </Label>
                            <Input
                                id={`${fileId}-field-label-${index}`}
                                value={field.label}
                                placeholder="Field name"
                                readOnly={!field._isCustom}
                                tabIndex={field._isCustom ? 0 : -1}
                                onChange={(event) => {
                                    if (field._isCustom) {
                                        onChange(
                                            fields.map((entry, entryIndex) =>
                                                entryIndex === index
                                                    ? { ...entry, label: event.target.value }
                                                    : entry,
                                            ),
                                        );
                                    }
                                }}
                                className={`h-8 border-transparent bg-transparent font-medium shadow-none focus-visible:ring-0 ${!field._isCustom ? 'pointer-events-none opacity-80' : ''}`}
                            />
                        </div>
                        <div className="h-5 w-px bg-slate-200" />
                        <div className="flex-[1.5]">
                            <Label htmlFor={`${fileId}-field-value-${index}`} className="sr-only">
                                Value
                            </Label>
                            <Input
                                id={`${fileId}-field-value-${index}`}
                                value={field.value}
                                placeholder="Extracted value"
                                onChange={(event) =>
                                    onChange(
                                        fields.map((entry, entryIndex) =>
                                            entryIndex === index
                                                ? { ...entry, value: event.target.value }
                                                : entry,
                                        ),
                                    )
                                }
                                className="h-8 border-transparent bg-transparent shadow-none focus-visible:ring-0"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-8 text-slate-400 hover:bg-red-50 hover:text-red-700"
                            onClick={() =>
                                onChange(fields.filter((_, entryIndex) => entryIndex !== index))
                            }
                            aria-label={`Delete field ${field.label || index + 1}`}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                ))}
            </div>
            {fields.length === 0 && (
                <div className="rounded-lg border border-dashed p-5 text-center text-slate-500 text-sm">
                    No fields were extracted. Add the information visible in the file.
                </div>
            )}
        </section>
    );
}

function EditableItems({
    fileId,
    items,
    onChange,
}: {
    fileId: number;
    items: DraftItem[];
    onChange: (items: DraftItem[]) => void;
}) {
    const columns = useMemo(() => {
        const present = Array.from(
            new Set(items.flatMap((item) => Object.keys(item).filter((key) => key !== '_key'))),
        );
        const ordered = defaultItemColumns.filter((key) => present.includes(key));
        const extra = present.filter((key) => !defaultItemColumns.includes(key));

        return [...ordered, ...extra].length > 0 ? [...ordered, ...extra] : defaultItemColumns;
    }, [items]);

    function addItem() {
        onChange([
            ...items,
            { _key: uniqueKey(), ...Object.fromEntries(columns.map((column) => [column, ''])) },
        ]);
    }

    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <h3 className="font-semibold">Items</h3>
                    <p className="text-slate-500 text-xs">Add, correct, or remove line items.</p>
                </div>
                <Button type="button" variant="outline" size="sm" onClick={addItem}>
                    <Plus /> Add item
                </Button>
            </div>
            {items.length > 0 ? (
                <div className="space-y-2">
                    {items.map((item, itemIndex) => (
                        <div key={item._key} className="rounded-lg border bg-white p-3 shadow-sm">
                            <div className="mb-2 flex items-center justify-between">
                                <p className="font-medium text-slate-500 text-xs uppercase tracking-wider">
                                    Item {itemIndex + 1}
                                </p>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 text-slate-400 text-xs hover:bg-red-50 hover:text-red-700"
                                    onClick={() =>
                                        onChange(items.filter((_, index) => index !== itemIndex))
                                    }
                                >
                                    <Trash2 className="mr-1.5 size-3.5" /> Delete
                                </Button>
                            </div>
                            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                                {columns.map((column) => (
                                    <div key={column} className="space-y-1">
                                        <Label
                                            htmlFor={`${fileId}-item-${itemIndex}-${column}`}
                                            className="text-slate-500 text-xs"
                                        >
                                            {friendlyLabel(column)}
                                        </Label>
                                        <Input
                                            id={`${fileId}-item-${itemIndex}-${column}`}
                                            value={item[column] ?? ''}
                                            onChange={(event) =>
                                                onChange(
                                                    items.map((entry, index) =>
                                                        index === itemIndex
                                                            ? {
                                                                  ...entry,
                                                                  [column]: event.target.value,
                                                              }
                                                            : entry,
                                                    ),
                                                )
                                            }
                                            className="h-8 text-sm"
                                        />
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="rounded-lg border border-dashed p-5 text-center text-slate-500 text-sm">
                    No line items were found. Add one only when it appears in the file.
                </div>
            )}
        </section>
    );
}

function DocumentHeader({
    selected,
    selectedIndex,
    fileCount,
    onPrevious,
    onNext,
}: {
    selected: ReviewFile;
    selectedIndex: number;
    fileCount: number;
    onPrevious: () => void;
    onNext: () => void;
}) {
    return (
        <div className="flex shrink-0 items-center justify-between gap-3 border-b p-3">
            <div className="flex min-w-0 items-center gap-2">
                <FileText className="size-4 shrink-0 text-primary" />
                <span className="truncate font-medium text-sm">{selected.name}</span>
            </div>
            <FileNavigator
                selectedIndex={selectedIndex}
                fileCount={fileCount}
                onPrevious={onPrevious}
                onNext={onNext}
            />
        </div>
    );
}

function FileNavigator({
    selectedIndex,
    fileCount,
    onPrevious,
    onNext,
}: {
    selectedIndex: number;
    fileCount: number;
    onPrevious: () => void;
    onNext: () => void;
}) {
    return (
        <nav
            className="flex items-center justify-between gap-2"
            aria-label="Scanned file navigation"
        >
            <Button
                type="button"
                size="icon"
                variant="outline"
                className="size-11"
                onClick={onPrevious}
                disabled={selectedIndex === 0}
                aria-label="Previous scanned file"
            >
                <ChevronLeft />
            </Button>
            <span className="min-w-20 text-center font-medium text-sm tabular-nums">
                {selectedIndex + 1} of {fileCount}
            </span>
            <Button
                type="button"
                size="icon"
                variant="outline"
                className="size-11"
                onClick={onNext}
                disabled={selectedIndex >= fileCount - 1}
                aria-label="Next scanned file"
            >
                <ChevronRight />
            </Button>
        </nav>
    );
}

function DocumentPreview({ file }: { file: ReviewFile }) {
    const [currentScale, setCurrentScale] = useState(1);

    if (file.content_type.startsWith('image/')) {
        return (
            <div className="relative flex h-full w-full flex-col overflow-hidden rounded bg-slate-100/50">
                <TransformWrapper
                    key={file.id}
                    initialScale={1}
                    minScale={0.5}
                    maxScale={4}
                    centerOnInit={true}
                    wheel={{ step: 0.01 }}
                    onTransform={(ref: { state: { scale: number } }) =>
                        setCurrentScale(ref.state.scale)
                    }
                    onZoom={(ref: { state: { scale: number } }) => setCurrentScale(ref.state.scale)}
                    onInit={(ref: { state: { scale: number } }) => setCurrentScale(ref.state.scale)}
                >
                    {({ zoomIn, zoomOut, resetTransform }) => (
                        <>
                            <div className="absolute right-4 bottom-4 z-10 flex gap-1 rounded-md border bg-white/90 p-1 shadow-md backdrop-blur-sm">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    onClick={() => zoomOut()}
                                    aria-label="Zoom out"
                                >
                                    <ZoomOut className="size-4" />
                                </Button>
                                <span className="flex w-12 items-center justify-center font-medium text-slate-500 text-xs tabular-nums">
                                    {Math.round(currentScale * 100)}%
                                </span>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    onClick={() => resetTransform()}
                                    aria-label="Reset zoom"
                                >
                                    <Maximize className="size-4" />
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-8"
                                    onClick={() => zoomIn()}
                                    aria-label="Zoom in"
                                >
                                    <ZoomIn className="size-4" />
                                </Button>
                            </div>
                            <TransformComponent
                                wrapperStyle={{ width: '100%', height: '100%' }}
                                contentStyle={{
                                    width: '100%',
                                    height: '100%',
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                }}
                            >
                                <img
                                    src={file.preview_url}
                                    alt={`Scanned document ${file.name}`}
                                    className="max-h-full max-w-full object-contain"
                                />
                            </TransformComponent>
                        </>
                    )}
                </TransformWrapper>
            </div>
        );
    }

    return (
        <iframe
            src={file.preview_url}
            title={`PDF preview ${file.name}`}
            className="h-full min-h-[500px] w-full rounded bg-white"
        />
    );
}

function friendlyLabel(value: string): string {
    return value
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replaceAll('_', ' ')
        .replace(/\b\w/g, (character) => character.toUpperCase());
}

function createDraft(data: ReviewData): ReviewDraft {
    return {
        document_type: data.document_type,
        fields: data.fields.map((field) => ({ ...field, _key: uniqueKey() })),
        items: data.items.map((item) => ({ ...item, _key: uniqueKey() })),
        ...(data._warnings ? { _warnings: data._warnings } : {}),
    };
}

function serializeDraft(draft: ReviewDraft): ReviewData {
    return {
        document_type: draft.document_type,
        fields: draft.fields.map(({ _key, _isCustom, ...field }) => field),
        items: draft.items.map(({ _key, ...item }) => item),
        ...(draft._warnings ? { _warnings: draft._warnings } : {}),
    };
}

function uniqueKey(): string {
    return crypto.randomUUID();
}
