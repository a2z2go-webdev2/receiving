import {
    Check,
    CheckCircle2,
    Code2,
    Copy,
    ExternalLink,
    Key,
    Layers,
    RefreshCw,
    Save,
    Settings,
    ShieldCheck,
    Webhook,
} from 'lucide-react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

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
    const [selectedSlug, setSelectedSlug] = useState<string>(() => sheets[0]?.slug || 'a2z2go');
    const [activeSection, setActiveSection] = useState<'sheet' | 'webhook' | 'script'>('sheet');

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
    const currentSheet = sheets.find((s) => s.slug === selectedSlug) || sheets[0];
    const currentConfig = configs[selectedSlug] || {
        id: currentSheet?.spreadsheet_id || '',
        name: currentSheet?.name || selectedSlug,
        secret: currentSheet?.webhook_secret || '',
        autoSync: currentSheet?.auto_sync_on_webhook ?? true,
    };

    const webhookUrl = `${baseUrl}/api/webhooks/sheets/${selectedSlug}`;
    const secret = currentConfig.secret || currentSheet?.webhook_secret || '';

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

    const generateAppsScriptCode = (slug: string, secretToken: string) => {
        return `/**
 * Google Apps Script Webhook Trigger for Receiving System
 * Add this script to Extensions > Apps Script in your Google Sheet
 */
function sendNewUploadWebhook(serialNumber) {
  const WEBHOOK_URL = "${baseUrl}/api/webhooks/sheets/${slug}";
  const WEBHOOK_SECRET = "${secretToken || 'YOUR_WEBHOOK_SECRET'}";

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
  sendNewUploadWebhook();
}`;
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] max-w-2xl overflow-y-auto bg-card text-foreground">
                <DialogHeader className="border-b pb-3">
                    <div className="flex items-center gap-2">
                        <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Settings className="size-4" />
                        </div>
                        <div>
                            <DialogTitle className="text-base font-semibold">
                                Google Sheets & Webhook Integration
                            </DialogTitle>
                            <DialogDescription className="text-xs">
                                Configure Google Spreadsheet IDs, real-time webhooks, and Apps
                                Script triggers.
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {/* Lane Selector Tabs */}
                    <div className="flex w-full gap-1 overflow-x-auto rounded-lg border bg-muted/50 p-1">
                        {sheets.map((s) => {
                            const isSelected = s.slug === selectedSlug;
                            return (
                                <button
                                    key={s.slug}
                                    type="button"
                                    onClick={() => setSelectedSlug(s.slug)}
                                    className={`flex flex-1 items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-xs font-semibold transition ${
                                        isSelected
                                            ? 'bg-card text-foreground shadow-sm'
                                            : 'text-muted-foreground hover:bg-card/50 hover:text-foreground'
                                    }`}
                                >
                                    <Layers className="size-3.5" />
                                    <span>{s.name}</span>
                                    {s.webhook_secret && (
                                        <span className="size-1.5 rounded-full bg-emerald-500" />
                                    )}
                                </button>
                            );
                        })}
                    </div>

                    {successMessage && (
                        <div className="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-500/10 p-3 font-semibold text-xs text-emerald-600 dark:text-emerald-300">
                            <CheckCircle2 className="size-4 shrink-0 text-emerald-500" />
                            <span>{successMessage}</span>
                        </div>
                    )}

                    {/* Section Switcher */}
                    <div className="flex items-center justify-between border-b pb-2">
                        <div className="flex items-center gap-2">
                            <Button
                                type="button"
                                size="sm"
                                variant={activeSection === 'sheet' ? 'default' : 'ghost'}
                                onClick={() => setActiveSection('sheet')}
                                className="h-7 text-xs"
                            >
                                <Settings className="mr-1 size-3.5" />
                                Spreadsheet ID
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={activeSection === 'webhook' ? 'default' : 'ghost'}
                                onClick={() => setActiveSection('webhook')}
                                className="h-7 text-xs"
                            >
                                <Webhook className="mr-1 size-3.5 text-emerald-500" />
                                Webhook Endpoint
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant={activeSection === 'script' ? 'default' : 'ghost'}
                                onClick={() => setActiveSection('script')}
                                className="h-7 text-xs"
                            >
                                <Code2 className="mr-1 size-3.5 text-indigo-500" />
                                Apps Script Code
                            </Button>
                        </div>

                        <Badge variant="outline" className="font-mono text-[10px]">
                            {selectedSlug.toUpperCase()}
                        </Badge>
                    </div>

                    {/* Section 1: Spreadsheet ID */}
                    {activeSection === 'sheet' && (
                        <Card className="border">
                            <CardHeader className="p-4 pb-2">
                                <CardTitle className="text-xs font-semibold">
                                    Google Sheet Link or ID
                                </CardTitle>
                                <CardDescription className="text-xs">
                                    Paste the full Google Sheet share link or spreadsheet ID for{' '}
                                    {currentSheet?.name}.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-3 p-4 pt-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="sheet-id-input" className="text-xs">
                                        Spreadsheet URL or Clean ID
                                    </Label>
                                    <div className="flex items-center gap-2">
                                        <Input
                                            id="sheet-id-input"
                                            type="text"
                                            placeholder="https://docs.google.com/spreadsheets/d/1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms/edit..."
                                            value={currentConfig.id}
                                            onChange={(e) =>
                                                setConfigs({
                                                    ...configs,
                                                    [selectedSlug]: {
                                                        ...currentConfig,
                                                        id: e.target.value,
                                                    },
                                                })
                                            }
                                            className="font-mono text-xs"
                                        />
                                        <Button
                                            type="button"
                                            size="sm"
                                            disabled={savingSlug === selectedSlug}
                                            onClick={() => handleSave(selectedSlug)}
                                            className="h-9 shrink-0 gap-1 text-xs"
                                        >
                                            <Save className="size-3.5" />
                                            <span>
                                                {savingSlug === selectedSlug ? 'Saving...' : 'Save'}
                                            </span>
                                        </Button>
                                    </div>
                                </div>

                                {currentConfig.id && (
                                    <div className="flex items-center justify-between pt-1">
                                        <span className="font-mono text-[11px] text-muted-foreground">
                                            Last Synced:{' '}
                                            {currentSheet?.last_synced_at
                                                ? new Date(
                                                      currentSheet.last_synced_at,
                                                  ).toLocaleString()
                                                : 'Never'}
                                        </span>
                                        <a
                                            href={`https://docs.google.com/spreadsheets/d/${currentConfig.id}`}
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="flex items-center gap-1 font-semibold text-xs text-primary hover:underline"
                                        >
                                            <span>Open in Google Sheets</span>
                                            <ExternalLink className="size-3" />
                                        </a>
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    )}

                    {/* Section 2: Webhook Endpoint */}
                    {activeSection === 'webhook' && (
                        <div className="space-y-3">
                            <div className="flex items-center gap-2 rounded-lg border border-emerald-500/20 bg-emerald-500/10 p-3 text-xs text-emerald-700 dark:text-emerald-300">
                                <ShieldCheck className="size-4 shrink-0 text-emerald-500" />
                                <div>
                                    <div className="font-semibold">Automated Live Ingestion</div>
                                    <p className="text-[11px] opacity-90">
                                        When a new row is added to Google Sheets, this endpoint
                                        ingests and matches PO records automatically.
                                    </p>
                                </div>
                            </div>

                            <Card className="border">
                                <CardContent className="space-y-4 p-4">
                                    <div className="space-y-1.5">
                                        <Label className="text-xs">Webhook Target URL</Label>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="text"
                                                readOnly
                                                value={webhookUrl}
                                                className="bg-muted/50 font-mono text-xs"
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    handleCopy(webhookUrl, `url_${selectedSlug}`)
                                                }
                                                className="h-9 shrink-0 gap-1 text-xs"
                                            >
                                                {copiedKey === `url_${selectedSlug}` ? (
                                                    <Check className="size-3.5 text-emerald-500" />
                                                ) : (
                                                    <Copy className="size-3.5" />
                                                )}
                                                <span>
                                                    {copiedKey === `url_${selectedSlug}`
                                                        ? 'Copied'
                                                        : 'Copy URL'}
                                                </span>
                                            </Button>
                                        </div>
                                    </div>

                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between">
                                            <Label className="text-xs">
                                                Webhook Secret Token (Header: X-Webhook-Secret)
                                            </Label>
                                            <span className="font-mono text-[10px] text-muted-foreground">
                                                Required for auth
                                            </span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Input
                                                type="text"
                                                readOnly
                                                value={secret}
                                                placeholder="Click Generate Secret to create token..."
                                                className="bg-muted/50 font-mono text-xs text-emerald-600 dark:text-emerald-400"
                                            />
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                disabled={!secret}
                                                onClick={() =>
                                                    handleCopy(secret, `sec_${selectedSlug}`)
                                                }
                                                className="h-9 shrink-0 gap-1 text-xs"
                                            >
                                                {copiedKey === `sec_${selectedSlug}` ? (
                                                    <Check className="size-3.5 text-emerald-500" />
                                                ) : (
                                                    <Copy className="size-3.5" />
                                                )}
                                                <span>
                                                    {copiedKey === `sec_${selectedSlug}`
                                                        ? 'Copied'
                                                        : 'Copy Secret'}
                                                </span>
                                            </Button>

                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="secondary"
                                                disabled={savingSlug === selectedSlug}
                                                onClick={() => handleGenerateSecret(selectedSlug)}
                                                className="h-9 shrink-0 gap-1 text-xs"
                                                title="Generate or rotate secret"
                                            >
                                                <RefreshCw
                                                    className={`size-3.5 ${savingSlug === selectedSlug ? 'animate-spin' : ''}`}
                                                />
                                                <span>{secret ? 'Regenerate' : 'Generate'}</span>
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        </div>
                    )}

                    {/* Section 3: Apps Script Code */}
                    {activeSection === 'script' && (
                        <Card className="border">
                            <CardHeader className="flex flex-row items-center justify-between space-y-0 p-4 pb-2">
                                <div>
                                    <CardTitle className="text-xs font-semibold">
                                        Google Apps Script Code ({currentSheet?.name})
                                    </CardTitle>
                                    <CardDescription className="text-xs">
                                        Open Extensions &rarr; Apps Script in your Google Sheet,
                                        paste this code, and save.
                                    </CardDescription>
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        handleCopy(
                                            generateAppsScriptCode(selectedSlug, secret),
                                            `code_${selectedSlug}`,
                                        )
                                    }
                                    className="h-8 gap-1 text-xs"
                                >
                                    {copiedKey === `code_${selectedSlug}` ? (
                                        <Check className="size-3.5 text-emerald-500" />
                                    ) : (
                                        <Copy className="size-3.5" />
                                    )}
                                    <span>
                                        {copiedKey === `code_${selectedSlug}`
                                            ? 'Copied Script!'
                                            : 'Copy Code'}
                                    </span>
                                </Button>
                            </CardHeader>
                            <CardContent className="p-4 pt-2">
                                <pre className="max-h-56 overflow-auto rounded-lg border bg-muted/60 p-3 font-mono text-[11px] text-foreground leading-relaxed">
                                    {generateAppsScriptCode(selectedSlug, secret)}
                                </pre>
                            </CardContent>
                        </Card>
                    )}
                </div>

                <DialogFooter className="border-t pt-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => onOpenChange(false)}
                        className="text-xs"
                    >
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
