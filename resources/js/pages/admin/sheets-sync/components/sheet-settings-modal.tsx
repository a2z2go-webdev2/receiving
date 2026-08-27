import { CheckCircle2, Code2, Copy, Save, Settings, ShieldCheck, Webhook } from 'lucide-react';
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

interface SheetConfigItem {
    id: number;
    slug: string;
    name: string;
    spreadsheet_id: string | null;
    webhook_secret?: string | null;
    auto_sync_on_webhook?: boolean;
    last_synced_at: string | null;
    total_serials: number;
    synced_serials: number;
    pending_serials: number;
    failed_serials: number;
}

interface SheetSettingsModalProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sheets: SheetConfigItem[];
    onSaved: (updatedSheet: SheetConfigItem) => void;
}

export function SheetSettingsModal({
    open,
    onOpenChange,
    sheets,
    onSaved,
}: SheetSettingsModalProps) {
    const [activeTab, setActiveTab] = useState<'settings' | 'webhook'>('settings');
    const [configs, setConfigs] = useState<
        Record<string, { id: string; name: string; secret: string; autoSync: boolean }>
    >(() => {
        const initial: Record<
            string,
            { id: string; name: string; secret: string; autoSync: boolean }
        > = {};
        sheets.forEach((s) => {
            initial[s.slug] = {
                id: s.spreadsheet_id || '',
                name: s.name,
                secret: s.webhook_secret || '',
                autoSync: s.auto_sync_on_webhook ?? true,
            };
        });
        return initial;
    });

    const [savingSlug, setSavingSlug] = useState<string | null>(null);
    const [successMessage, setSuccessMessage] = useState<string | null>(null);
    const [copiedKey, setCopiedKey] = useState<string | null>(null);

    const baseUrl = typeof window !== 'undefined' ? window.location.origin : '';

    const handleCopy = (text: string, key: string) => {
        navigator.clipboard.writeText(text);
        setCopiedKey(key);
        setTimeout(() => setCopiedKey(null), 2500);
    };

    const handleSave = async (slug: string) => {
        setSavingSlug(slug);
        setSuccessMessage(null);

        try {
            const res = await fetch('/admin/sheets-sync/config', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                            ?.content || '',
                },
                body: JSON.stringify({
                    slug,
                    spreadsheet_id: configs[slug]?.id || '',
                    name: configs[slug]?.name || '',
                }),
            });

            const data = await res.json();
            if (res.ok && data.success) {
                setSuccessMessage(`Settings saved for ${configs[slug]?.name || slug}`);
                onSaved(data.sheet);
                setTimeout(() => setSuccessMessage(null), 3000);
            }
        } catch {
        } finally {
            setSavingSlug(null);
        }
    };

    const handleGenerateSecret = async (slug: string) => {
        setSavingSlug(slug);
        try {
            const res = await fetch('/admin/sheets-sync/generate-secret', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)
                            ?.content || '',
                },
                body: JSON.stringify({ slug }),
            });

            const data = await res.json();
            if (res.ok && data.success && data.secret) {
                setConfigs((prev) => ({
                    ...prev,
                    [slug]: {
                        ...prev[slug],
                        secret: data.secret,
                    },
                }));
                setSuccessMessage(
                    `New webhook secret generated for ${configs[slug]?.name || slug}`,
                );
                onSaved(data.sheet);
                setTimeout(() => setSuccessMessage(null), 3000);
            }
        } catch {
        } finally {
            setSavingSlug(null);
        }
    };

    const generateAppsScriptCode = (slug: string, secret: string) => {
        return `/**
 * Google Apps Script Webhook Trigger for Receiving System
 * Add this to Extensions > Apps Script in your Google Sheet
 */
function sendNewUploadWebhook(serialNumber) {
  const WEBHOOK_URL = "${baseUrl}/api/webhooks/sheets/${slug}";
  const WEBHOOK_SECRET = "${secret || 'YOUR_WEBHOOK_SECRET'}";

  const payload = {
    serial_number: serialNumber || 1,
    event: "upload_created"
  };

  const options = {
    method: "POST",
    contentType: "application/json",
    headers: {
      "X-Webhook-Secret": WEBHOOK_SECRET
    },
    payload: JSON.stringify(payload),
    muteHttpExceptions: true
  };

  try {
    const response = UrlFetchApp.fetch(WEBHOOK_URL, options);
    Logger.log("Webhook response: " + response.getContentText());
  } catch (err) {
    Logger.log("Webhook error: " + err.toString());
  }
}

// Automatically trigger on new row addition or edit
function onNewUploadRow(e) {
  // Call sendNewUploadWebhook with the newly added serial number
  sendNewUploadWebhook();
}`;
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[85vh] max-w-3xl overflow-y-auto border border-slate-800 bg-slate-900 p-6 text-slate-100 shadow-2xl">
                <DialogHeader className="border-slate-800 border-b pb-3">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <Settings className="h-5 w-5 text-indigo-400" />
                            <DialogTitle className="font-bold text-lg text-white">
                                Google Sheets & Webhook Integration
                            </DialogTitle>
                        </div>
                        <div className="flex items-center gap-1 rounded-xl border border-slate-800 bg-slate-950 p-1">
                            <button
                                type="button"
                                onClick={() => setActiveTab('settings')}
                                className={`rounded-lg px-3 py-1 font-bold text-xs transition ${
                                    activeTab === 'settings'
                                        ? 'bg-indigo-600 text-white shadow-md'
                                        : 'text-slate-400 hover:text-white'
                                }`}
                            >
                                Sheet IDs
                            </button>
                            <button
                                type="button"
                                onClick={() => setActiveTab('webhook')}
                                className={`flex items-center gap-1.5 rounded-lg px-3 py-1 font-bold text-xs transition ${
                                    activeTab === 'webhook'
                                        ? 'bg-indigo-600 text-white shadow-md'
                                        : 'text-slate-400 hover:text-white'
                                }`}
                            >
                                <Webhook className="h-3.5 w-3.5 text-emerald-400" />
                                <span>Live Webhooks</span>
                            </button>
                        </div>
                    </div>
                    <DialogDescription className="text-slate-400 text-xs">
                        Configure Google Sheets spreadsheet IDs, automated webhook URLs, and Google
                        Apps Script triggers.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {successMessage && (
                        <div className="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 font-semibold text-emerald-300 text-xs">
                            <CheckCircle2 className="h-4 w-4 text-emerald-400" />
                            <span>{successMessage}</span>
                        </div>
                    )}

                    {activeTab === 'settings' ? (
                        <div className="space-y-3">
                            {sheets.map((sheet) => {
                                const current = configs[sheet.slug] || {
                                    id: '',
                                    name: sheet.name,
                                    secret: '',
                                    autoSync: true,
                                };
                                const isSaving = savingSlug === sheet.slug;

                                return (
                                    <div
                                        key={sheet.slug}
                                        className="space-y-3 rounded-xl border border-slate-800 bg-slate-950/80 p-4"
                                    >
                                        <div className="flex items-center justify-between">
                                            <span className="font-bold text-sm text-white uppercase tracking-wider">
                                                {sheet.name} ({sheet.slug})
                                            </span>
                                            <span className="font-mono text-[11px] text-slate-400">
                                                Last Synced:{' '}
                                                {sheet.last_synced_at
                                                    ? new Date(
                                                          sheet.last_synced_at,
                                                      ).toLocaleDateString()
                                                    : 'Never'}
                                            </span>
                                        </div>

                                        <div className="space-y-1">
                                            <span className="block font-semibold text-[11px] text-slate-400">
                                                Spreadsheet URL or Clean ID
                                            </span>
                                            <div className="flex items-center gap-2">
                                                <input
                                                    type="text"
                                                    placeholder="https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit..."
                                                    value={current.id}
                                                    onChange={(e) =>
                                                        setConfigs({
                                                            ...configs,
                                                            [sheet.slug]: {
                                                                ...current,
                                                                id: e.target.value,
                                                            },
                                                        })
                                                    }
                                                    className="flex-1 rounded-lg border border-slate-700 bg-slate-900 p-2 font-mono text-slate-200 text-xs focus:border-indigo-500 focus:outline-none"
                                                />
                                                <Button
                                                    type="button"
                                                    disabled={isSaving}
                                                    onClick={() => handleSave(sheet.slug)}
                                                    className="bg-indigo-600 px-3 font-semibold text-white text-xs hover:bg-indigo-500"
                                                >
                                                    <Save className="mr-1 h-3.5 w-3.5" />
                                                    <span>{isSaving ? 'Saving...' : 'Save'}</span>
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <div className="space-y-1 rounded-xl border border-emerald-500/20 bg-emerald-500/10 p-3.5 text-emerald-300 text-xs">
                                <div className="flex items-center gap-1.5 font-bold">
                                    <ShieldCheck className="h-4 w-4 text-emerald-400" />
                                    <span>Real-Time Webhook Synchronization Active</span>
                                </div>
                                <p className="text-[11px] text-emerald-200/80">
                                    When a new row is added in Google Sheets, Google Apps Script
                                    sends a request to the webhook endpoint. The upload will
                                    automatically be staged and synchronized into the database in
                                    real-time.
                                </p>
                            </div>

                            {sheets.map((sheet) => {
                                const webhookUrl = `${baseUrl}/api/webhooks/sheets/${sheet.slug}`;
                                const secret = sheet.webhook_secret || `whsec_${sheet.slug}`;

                                return (
                                    <div
                                        key={sheet.slug}
                                        className="space-y-3 rounded-xl border border-slate-800 bg-slate-950 p-4"
                                    >
                                        <div className="flex items-center justify-between">
                                            <div className="flex items-center gap-2">
                                                <Webhook className="h-4 w-4 text-indigo-400" />
                                                <span className="font-bold text-sm text-white">
                                                    {sheet.name} Webhook
                                                </span>
                                            </div>
                                            <span className="rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2 py-0.5 font-mono text-[10px] text-emerald-400">
                                                POST {`/api/webhooks/sheets/${sheet.slug}`}
                                            </span>
                                        </div>

                                        {/* Webhook URL */}
                                        <div>
                                            <span className="block font-bold text-[10px] text-slate-500 uppercase">
                                                Webhook Endpoint URL
                                            </span>
                                            <div className="mt-1 flex items-center gap-2">
                                                <input
                                                    type="text"
                                                    readOnly
                                                    value={webhookUrl}
                                                    className="flex-1 select-all rounded-lg border border-slate-800 bg-slate-900 p-2 font-mono text-slate-300 text-xs"
                                                />
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        handleCopy(webhookUrl, `url_${sheet.slug}`)
                                                    }
                                                    className="h-8 font-semibold text-xs"
                                                >
                                                    {copiedKey === `url_${sheet.slug}` ? (
                                                        <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
                                                    ) : (
                                                        <Copy className="h-3.5 w-3.5" />
                                                    )}
                                                    <span className="ml-1">Copy URL</span>
                                                </Button>
                                            </div>
                                        </div>

                                        {/* Webhook Secret */}
                                        <div>
                                            <span className="block font-bold text-[10px] text-slate-500 uppercase">
                                                Secret Token Header (X-Webhook-Secret)
                                            </span>
                                            <div className="mt-1 flex items-center gap-2">
                                                <input
                                                    type="text"
                                                    readOnly
                                                    value={secret}
                                                    placeholder="Click Generate Secret to create token..."
                                                    className="flex-1 select-all rounded-lg border border-slate-800 bg-slate-900 p-2 font-mono text-emerald-400 text-xs"
                                                />
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={!secret}
                                                    onClick={() =>
                                                        handleCopy(secret, `sec_${sheet.slug}`)
                                                    }
                                                    className="h-8 font-semibold text-xs"
                                                >
                                                    {copiedKey === `sec_${sheet.slug}` ? (
                                                        <CheckCircle2 className="h-3.5 w-3.5 text-emerald-400" />
                                                    ) : (
                                                        <Copy className="h-3.5 w-3.5" />
                                                    )}
                                                    <span className="ml-1">Copy Secret</span>
                                                </Button>

                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={savingSlug === sheet.slug}
                                                    onClick={() => handleGenerateSecret(sheet.slug)}
                                                    className="h-8 border-indigo-500/40 bg-indigo-950/40 text-indigo-300 hover:bg-indigo-900/60 font-semibold text-xs"
                                                    title="Generate or rotate a new Webhook Secret token"
                                                >
                                                    <RefreshCw
                                                        className={`h-3.5 w-3.5 ${savingSlug === sheet.slug ? 'animate-spin' : ''}`}
                                                    />
                                                    <span className="ml-1">
                                                        {secret ? 'Regenerate' : 'Generate Secret'}
                                                    </span>
                                                </Button>
                                            </div>
                                        </div>

                                        {/* Apps script snippet */}
                                        <div className="pt-1">
                                            <div className="mb-1 flex items-center justify-between">
                                                <span className="flex items-center gap-1 font-bold text-[10px] text-slate-400 uppercase">
                                                    <Code2 className="h-3 w-3 text-indigo-400" />
                                                    <span>
                                                        Google Apps Script Code for {sheet.name}
                                                    </span>
                                                </span>
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        handleCopy(
                                                            generateAppsScriptCode(
                                                                sheet.slug,
                                                                secret,
                                                            ),
                                                            `code_${sheet.slug}`,
                                                        )
                                                    }
                                                    className="flex items-center gap-1 font-semibold text-[11px] text-indigo-400 hover:text-indigo-300"
                                                >
                                                    <Copy className="h-3 w-3" />
                                                    <span>
                                                        {copiedKey === `code_${sheet.slug}`
                                                            ? 'Copied Code!'
                                                            : 'Copy Script'}
                                                    </span>
                                                </button>
                                            </div>
                                            <pre className="max-h-32 overflow-x-auto rounded-xl border border-slate-800/80 bg-slate-950 p-3 font-mono text-[11px] text-slate-300">
                                                {generateAppsScriptCode(sheet.slug, secret)}
                                            </pre>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                <DialogFooter className="border-slate-800 border-t pt-3">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={() => onOpenChange(false)}
                        className="bg-slate-800 text-slate-300 text-xs hover:bg-slate-700"
                    >
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
