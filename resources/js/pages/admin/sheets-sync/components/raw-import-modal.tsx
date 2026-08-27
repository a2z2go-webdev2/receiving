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
        } catch (e: any) {
            setError(e.message || 'Network error occurred while importing.');
        } finally {
            setImporting(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-2xl">
                <DialogHeader className="border-slate-800 border-b pb-3">
                    <div className="flex items-center gap-2">
                        <FileText className="h-5 w-5 text-indigo-400" />
                        <DialogTitle className="font-bold text-lg text-white">
                            Direct Table Import for {sheetName}
                        </DialogTitle>
                    </div>
                    <DialogDescription className="text-slate-400 text-xs">
                        Paste exported HTML table code (from <code>sheet001.htm</code>,{' '}
                        <code>sheet002.htm</code>, <code>sheet003.htm</code> or Excel/Sheets web
                        export) or CSV text.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-3 py-2">
                    {error && (
                        <div className="rounded-lg border border-rose-500/30 bg-rose-500/10 p-3 font-medium text-rose-300 text-xs">
                            {error}
                        </div>
                    )}

                    <p className="text-slate-400 text-xs">
                        You can import any of the 3 tabs (<strong>Receiving_Log</strong>,{' '}
                        <strong>receive_files</strong>, or <strong>ai_extraction</strong>). Rows
                        will be matched and staged automatically by Serial Number.
                    </p>

                    <textarea
                        rows={12}
                        placeholder="Paste HTML table (e.g. <table>...<tr>...</tr></table>) or CSV rows here..."
                        value={content}
                        onChange={(e) => setContent(e.target.value)}
                        className="w-full rounded-lg border border-slate-800 bg-slate-950 p-3 font-mono text-slate-200 text-xs placeholder-slate-600 focus:border-indigo-500 focus:outline-none"
                    />
                </div>

                <DialogFooter className="border-slate-800 border-t pt-3">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                        className="bg-slate-800 text-slate-300 text-xs hover:bg-slate-700"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={importing || !content.trim()}
                        onClick={handleImport}
                        className="flex items-center gap-1.5 bg-indigo-600 font-bold text-white text-xs shadow-indigo-600/30 shadow-lg hover:bg-indigo-500"
                    >
                        <UploadCloud className="h-4 w-4" />
                        <span>{importing ? 'Importing...' : `Import to ${sheetName}`}</span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
