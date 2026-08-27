import { FileText, UploadCloud } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

interface RawImportModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sheetSlug: string;
    sheetName: string;
    onImportSuccess: (message: string) => void;
}

export function RawImportModal({
    open,
    onOpenChange,
    sheetSlug,
    sheetName,
    onImportSuccess,
}: RawImportModalProps) {
    const [content, setContent] = useState<string>('');
    const [importing, setImporting] = useState<boolean>(false);
    const [error, setError] = useState<string | null>(null);

    const handleImport = async () => {
        if (!content.trim()) {
            setError('Please paste HTML table or CSV content.');
            return;
        }

        setImporting(true);
        setError(null);

        try {
            const res = await fetch(`/admin/sheets-sync/import-raw/${sheetSlug}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                            ?.content || '',
                },
                body: JSON.stringify({ content }),
            });

            const data = await res.json();
            if (res.ok && data.success) {
                setContent('');
                onImportSuccess(data.message);
                onOpenChange(false);
            } else {
                setError(data.error || 'Failed to import table content.');
            }
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Network error occurred while importing.');
        } finally {
            setImporting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl bg-card text-foreground">
                <DialogHeader className="border-b pb-3">
                    <div className="flex items-center gap-2">
                        <div className="flex size-8 items-center justify-center rounded-lg bg-indigo-500/10 text-indigo-600 dark:text-indigo-400">
                            <FileText className="size-4" />
                        </div>
                        <div>
                            <DialogTitle className="text-base font-semibold">
                                Direct Table Import for {sheetName}
                            </DialogTitle>
                            <DialogDescription className="text-xs">
                                Paste exported HTML table source code (e.g.{' '}
                                <code>sheet001.htm</code>, <code>sheet002.htm</code>, or Excel
                                export) or CSV rows.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="space-y-3 py-2">
                    {error && (
                        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 text-xs text-rose-600 dark:text-rose-300">
                            {error}
                        </div>
                    )}

                    <p className="text-xs text-muted-foreground">
                        You can paste any sheet tab (<strong>Receiving_Log</strong>,{' '}
                        <strong>receive_files</strong>, or <strong>ai_extraction</strong>). Rows
                        will automatically be matched and staged by Serial Number.
                    </p>

                    <div className="space-y-1.5">
                        <Label htmlFor="raw-content-textarea" className="text-xs">
                            HTML Table Source Code or CSV Text
                        </Label>
                        <textarea
                            id="raw-content-textarea"
                            rows={10}
                            placeholder="Paste HTML table (e.g. <table>...<tr>...</tr></table>) or CSV text here..."
                            value={content}
                            onChange={(e) => setContent(e.target.value)}
                            className="w-full rounded-md border border-input bg-background p-3 font-mono text-xs text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        />
                    </div>
                </div>

                <DialogFooter className="border-t pt-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                        className="text-xs"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        disabled={importing || !content.trim()}
                        onClick={handleImport}
                        className="gap-1.5 bg-primary text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                    >
                        <UploadCloud className="size-3.5" />
                        <span>{importing ? 'Processing & Staging...' : 'Import Content'}</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
