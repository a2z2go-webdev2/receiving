import React, { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { Upload, FileSpreadsheet, Loader2, AlertCircle, Folder } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';

type Props = {
    uploadType: { id: number; name: string; slug: string };
};

export function LegacyImportDialog({ uploadType }: Props) {
    const [open, setOpen] = useState(false);
    const [importMode, setImportMode] = useState<'path' | 'files'>('path');

    const { data, setData, post, processing, errors, reset } = useForm<{
        directory_path: string;
        logs_file: File | null;
        files_file: File | null;
        extractions_file: File | null;
    }>({
        directory_path: 'C:\\Users\\durin\\Downloads\\PINGCON - RECEIVING-20260729T014327Z-1-001\\PINGCON - RECEIVING',
        logs_file: null,
        files_file: null,
        extractions_file: null,
    });

    const hasErrors = Object.keys(errors).length > 0;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(`/admin/upload-types/${uploadType.slug}/legacy-import`, {
            forceFormData: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline" className="gap-1.5 text-xs">
                    <FileSpreadsheet className="h-3.5 w-3.5" />
                    Import Legacy Data
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Import Legacy Data: {uploadType.name}</DialogTitle>
                    <DialogDescription>
                        Import legacy receiving spreadsheet exports (CSV or HTML format) into the database.
                    </DialogDescription>
                </DialogHeader>

                <div className="mt-2 flex border-b text-xs">
                    <button
                        type="button"
                        onClick={() => setImportMode('path')}
                        className={`flex items-center gap-1.5 border-b-2 px-3 py-2 font-medium ${
                            importMode === 'path'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Folder className="h-3.5 w-3.5" />
                        Server Folder Path (Recommended)
                    </button>
                    <button
                        type="button"
                        onClick={() => setImportMode('files')}
                        className={`flex items-center gap-1.5 border-b-2 px-3 py-2 font-medium ${
                            importMode === 'files'
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Upload className="h-3.5 w-3.5" />
                        Upload Files via Browser
                    </button>
                </div>

                <form onSubmit={handleSubmit} className="mt-4 space-y-4">
                    {hasErrors && (
                        <div className="flex items-start gap-2 rounded-md border border-destructive/50 bg-destructive/10 p-3 text-xs text-destructive">
                            <AlertCircle className="h-4 w-4 shrink-0 mt-0.5" />
                            <div>
                                <p className="font-semibold">Import Error</p>
                                {Object.values(errors).map((err, idx) => (
                                    <p key={idx} className="mt-0.5">{err}</p>
                                ))}
                            </div>
                        </div>
                    )}

                    {importMode === 'path' ? (
                        <div className="space-y-1.5">
                            <label className="text-xs font-semibold text-foreground">
                                Folder Path Containing Export Files (HTML or CSV)
                            </label>
                            <input
                                type="text"
                                value={data.directory_path}
                                onChange={(e) => setData('directory_path', e.target.value)}
                                placeholder="C:\Users\...\Downloads\PINGCON - RECEIVING"
                                className="block w-full rounded-md border bg-background px-3 py-2 text-xs font-mono text-foreground shadow-sm focus:border-primary focus:outline-none"
                                required
                            />
                            {errors.directory_path && (
                                <p className="text-xs text-destructive">{errors.directory_path}</p>
                            )}
                            <p className="text-[11px] text-muted-foreground">
                                Bypasses browser upload limits. Scans folder for <code>Receiving_Log</code>, <code>receive_files</code>, and <code>ai_extraction</code> files.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-3">
                            <div className="space-y-1.5">
                                <label className="text-xs font-semibold text-foreground">
                                    1. Receiving Log File (CSV or HTML) *
                                </label>
                                <input
                                    type="file"
                                    accept=".html,.htm,.csv,.txt"
                                    onChange={(e) => {
                                        setData('directory_path', '');
                                        setData('logs_file', e.target.files?.[0] || null);
                                    }}
                                    className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-xs file:font-medium hover:file:bg-secondary/80"
                                    required={importMode === 'files'}
                                />
                                {errors.logs_file && (
                                    <p className="text-xs text-destructive">{errors.logs_file}</p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-xs font-semibold text-foreground">
                                    2. Receive Files List (Optional - CSV or HTML)
                                </label>
                                <input
                                    type="file"
                                    accept=".html,.htm,.csv,.txt"
                                    onChange={(e) => setData('files_file', e.target.files?.[0] || null)}
                                    className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-xs file:font-medium hover:file:bg-secondary/80"
                                />
                                {errors.files_file && (
                                    <p className="text-xs text-destructive">{errors.files_file}</p>
                                )}
                            </div>

                            <div className="space-y-1.5">
                                <label className="text-xs font-semibold text-foreground">
                                    3. AI Extractions File (Optional - CSV or HTML)
                                </label>
                                <input
                                    type="file"
                                    accept=".html,.htm,.csv,.txt"
                                    onChange={(e) => setData('extractions_file', e.target.files?.[0] || null)}
                                    className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-xs file:font-medium hover:file:bg-secondary/80"
                                />
                                {errors.extractions_file && (
                                    <p className="text-xs text-destructive">{errors.extractions_file}</p>
                                )}
                            </div>
                        </div>
                    )}

                    <div className="rounded-md border bg-muted/30 p-3 text-xs text-muted-foreground">
                        <p className="font-semibold text-foreground">Multi-Format & Folder Preservation</p>
                        <p className="mt-1">
                            The system automatically joins records by Serial Number and Google Drive File ID. Files will be queued for automatic background transfer to Cloudflare R2.
                        </p>
                    </div>

                    {processing && (
                        <div className="flex items-center gap-2 rounded-md bg-accent p-3 text-xs text-accent-foreground font-medium animate-pulse">
                            <Loader2 className="h-4 w-4 animate-spin shrink-0" />
                            <span>Processing legacy dataset and building database records... Please do not close this window.</span>
                        </div>
                    )}

                    <div className="flex justify-end gap-2 pt-2">
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setOpen(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={
                                processing ||
                                (importMode === 'path' && !data.directory_path) ||
                                (importMode === 'files' && !data.logs_file)
                            }
                            className="gap-1.5"
                        >
                            {processing ? (
                                <>
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                    Importing...
                                </>
                            ) : (
                                <>
                                    <Upload className="h-4 w-4" />
                                    Start Import
                                </>
                            )}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
