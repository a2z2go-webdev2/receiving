import { Form, Head, router, usePage } from '@inertiajs/react';
import { Check, Clipboard, KeyRound, Loader2, Play, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

type ApiKeyRow = {
    id: number;
    name: string;
    prefix: string;
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
};

type ApiEndpoints = {
    serial: string;
    poNumber: string;
};

export default function ApiKeys({
    apiKeys,
    newApiKey,
    endpoints,
}: {
    apiKeys: ApiKeyRow[];
    newApiKey: string | null;
    endpoints: ApiEndpoints;
}) {
    const [copied, setCopied] = useState(false);
    const [copyError, setCopyError] = useState('');
    const [revoking, setRevoking] = useState<ApiKeyRow | null>(null);
    const { url } = usePage();

    const searchParams = new URLSearchParams(url.includes('?') ? url.split('?')[1] : '');
    const tabQuery = searchParams.get('tab');
    const activeTab = tabQuery === 'guide' ? 'guide' : tabQuery === 'tester' ? 'tester' : 'keys';

    const setActiveTab = (tab: 'keys' | 'guide' | 'tester') => {
        router.visit(`/settings/api-keys?tab=${tab}`, {
            preserveState: true,
            preserveScroll: true,
        });
    };

    async function copyKey() {
        if (!newApiKey) return;

        try {
            await navigator.clipboard.writeText(newApiKey);
            setCopied(true);
            setCopyError('');
        } catch {
            setCopyError('Copy failed. Select the key text and copy it manually.');
        }
    }

    return (
        <>
            <Head title="API keys" />
            <h1 className="sr-only">API keys</h1>

            <div className="space-y-3">
                <Heading
                    variant="small"
                    title="Invoice and receipt data API"
                    description="Create revocable keys for systems that need verified corrections or the latest raw AI extraction."
                />

                <div className="flex gap-4 border-muted border-b text-xs">
                    <button
                        type="button"
                        onClick={() => setActiveTab('keys')}
                        className={cn(
                            '-mb-px border-b-2 px-1 pb-2 font-medium transition-colors',
                            activeTab === 'keys'
                                ? 'border-primary font-semibold text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        Keys Manager
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('guide')}
                        className={cn(
                            '-mb-px border-b-2 px-1 pb-2 font-medium transition-colors',
                            activeTab === 'guide'
                                ? 'border-primary font-semibold text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        Integration Guide
                    </button>
                    <button
                        type="button"
                        onClick={() => setActiveTab('tester')}
                        className={cn(
                            '-mb-px border-b-2 px-1 pb-2 font-medium transition-colors',
                            activeTab === 'tester'
                                ? 'border-primary font-semibold text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        API Tester
                    </button>
                </div>

                {activeTab === 'keys' ? (
                    <div className="space-y-3">
                        {newApiKey && (
                            <section className="space-y-2.5 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-emerald-950 text-xs">
                                <div>
                                    <h2 className="font-semibold text-emerald-950">
                                        Copy your new API key now
                                    </h2>
                                    <p className="text-[11px] text-emerald-800 leading-relaxed">
                                        This is the only time the complete key will be shown. Store
                                        it in the other system's secret manager.
                                    </p>
                                </div>
                                <div className="flex flex-col gap-2 sm:flex-row">
                                    <code className="min-w-0 flex-1 break-all rounded-md border border-emerald-300 bg-white px-2.5 py-1.5 font-mono text-[10px] leading-normal">
                                        {newApiKey}
                                    </code>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={copyKey}
                                        className="h-[30px] px-3 text-xs"
                                    >
                                        {copied ? (
                                            <Check className="size-3.5" />
                                        ) : (
                                            <Clipboard className="size-3.5" />
                                        )}
                                        {copied ? 'Copied' : 'Copy key'}
                                    </Button>
                                </div>
                                {copyError && (
                                    <p className="mt-1 text-[11px] text-red-700">{copyError}</p>
                                )}
                            </section>
                        )}

                        <section className="space-y-2.5 rounded-lg border p-3">
                            <div>
                                <h2 className="font-semibold text-sm">Generate API key</h2>
                                <p className="text-muted-foreground text-xs leading-normal">
                                    Keys can only read invoice and receipt extraction data and can
                                    be revoked at any time.
                                </p>
                            </div>
                            <Form
                                action="/settings/api-keys"
                                method="post"
                                className="space-y-2.5"
                                resetOnSuccess
                            >
                                {({ errors, processing }) => (
                                    <>
                                        <div className="grid gap-1">
                                            <Label htmlFor="name">Key name</Label>
                                            <Input
                                                id="name"
                                                name="name"
                                                placeholder="Accounting integration"
                                                required
                                                maxLength={80}
                                                className="h-[30px] text-xs"
                                            />
                                            <InputError message={errors.name} />
                                        </div>
                                        <div className="grid gap-1">
                                            <Label htmlFor="expires_in_days">Expires after</Label>
                                            <Select name="expires_in_days" defaultValue="90">
                                                <SelectTrigger
                                                    id="expires_in_days"
                                                    className="h-[30px] w-full text-xs"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="30">30 days</SelectItem>
                                                    <SelectItem value="90">90 days</SelectItem>
                                                    <SelectItem value="365">1 year</SelectItem>
                                                    <SelectItem value="never">Never</SelectItem>
                                                </SelectContent>
                                            </Select>
                                            <InputError message={errors.expires_in_days} />
                                            <p className="text-[11px] text-muted-foreground leading-relaxed">
                                                Never-expiring keys remain valid until manually
                                                revoked. Prefer an expiry whenever the connected
                                                system supports key rotation.
                                            </p>
                                        </div>
                                        <Button
                                            disabled={processing}
                                            className="mt-1 h-[30px] text-xs"
                                        >
                                            <KeyRound className="size-3.5" />{' '}
                                            {processing ? 'Generating...' : 'Generate key'}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </section>

                        <section className="space-y-2">
                            <h2 className="font-semibold text-sm">Active keys</h2>
                            {apiKeys.length === 0 ? (
                                <p className="rounded-lg border border-dashed p-3.5 text-center text-muted-foreground text-xs">
                                    No API keys have been created yet.
                                </p>
                            ) : (
                                <div className="divide-y overflow-hidden rounded-lg border">
                                    {apiKeys.map((apiKey) => (
                                        <div
                                            key={apiKey.id}
                                            className="flex flex-col gap-2 p-2.5 transition-colors hover:bg-muted/10 sm:flex-row sm:items-center sm:justify-between"
                                        >
                                            <div className="min-w-0">
                                                <p className="font-medium text-xs">{apiKey.name}</p>
                                                <p className="truncate font-mono text-[10px] text-muted-foreground leading-normal">
                                                    {apiKey.prefix}...
                                                </p>
                                                <p className="mt-0.5 text-[10.5px] text-muted-foreground">
                                                    {apiKey.expires_at
                                                        ? `Expires ${new Date(apiKey.expires_at).toLocaleDateString()}`
                                                        : 'Never expires'}
                                                    {apiKey.last_used_at
                                                        ? ` - Last used ${new Date(apiKey.last_used_at).toLocaleDateString()}`
                                                        : ' - Never used'}
                                                </p>
                                            </div>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                onClick={() => setRevoking(apiKey)}
                                                className="h-[26px] self-start px-2.5 text-[11px] sm:self-center"
                                            >
                                                <Trash2 className="size-3" /> Revoke
                                            </Button>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    </div>
                ) : activeTab === 'guide' ? (
                    <ApiDocumentation endpoints={endpoints} />
                ) : (
                    <ApiTester endpoints={endpoints} defaultApiKey={newApiKey} />
                )}
            </div>

            <Dialog open={revoking !== null} onOpenChange={(open) => !open && setRevoking(null)}>
                <DialogContent hideCloseButton>
                    <DialogHeader>
                        <DialogTitle>Revoke this API key?</DialogTitle>
                        <DialogDescription>
                            {revoking?.name} will stop working immediately. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setRevoking(null)}>
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (!revoking) return;
                                router.delete(`/settings/api-keys/${revoking.id}`, {
                                    preserveScroll: true,
                                    onSuccess: () => setRevoking(null),
                                });
                            }}
                        >
                            Revoke key
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

function ApiDocumentation({ endpoints }: { endpoints: ApiEndpoints }) {
    const responseExample = `{
  "data": [{
    "id": 42,
    "upload": { "id": 14, "submission_id": "...", "upload_type": { "slug": "a2z2go" } },
    "source_file": {
      "id": 16,
      "name": "example-invoice.jpg",
      "url": "https://storage.example.com/..."
    },
    "document_type": "Invoice",
    "invoice_number": "INV-2026-0042",
    "po_number": "PO-2026-0042",
    "po_date": "2026-07-03",
    "verification_status": "verified",
    "corrected_data": { "document_type": "Invoice", "fields": [], "items": [] },
    "reviewed_at": "2026-07-02T12:00:00.000000Z"
  }],
  "meta": { "per_page": 50, "has_more": false, "next_after_id": null }
}`;

    return (
        <section className="w-full min-w-0 space-y-4 overflow-hidden rounded-lg border p-3.5 sm:p-4">
            <div className="flex items-start gap-3">
                <div className="rounded-full bg-primary/10 p-2 text-primary">
                    <ShieldCheck className="size-5" />
                </div>
                <div>
                    <h2 className="font-semibold text-sm">Integration guide</h2>
                    <p className="text-muted-foreground text-xs leading-normal">
                        Use HTTPS in production and send the API key in the Bearer authorization
                        header on every request.
                    </p>
                </div>
            </div>

            <div className="min-w-0 space-y-2.5">
                <h3 className="font-medium text-xs sm:text-sm">1. Authenticate your request</h3>
                <p className="text-muted-foreground text-xs leading-relaxed">
                    Pass your API key as a Bearer token in the{' '}
                    <code className="text-[11px]">Authorization</code> header on every request.
                    Choose your language below:
                </p>
                <CodeExamples endpoints={endpoints} />
            </div>

            <div className="min-w-0 space-y-2.5">
                <h3 className="font-medium text-xs sm:text-sm">2. Choose an endpoint</h3>
                <div className="grid min-w-0 gap-2.5">
                    <EndpointCard
                        title="By serial number"
                        description="Accepts 14 or SN-14 and returns every invoice or receipt file under that receiving upload."
                        url={`${endpoints.serial}?serial_number=SN-14&after_id=0&per_page=50`}
                    />
                    <EndpointCard
                        title="By PO Number"
                        description="Returns only invoice or receipt files whose selected data contains the provided PO Number."
                        url={`${endpoints.poNumber}?po_number=12345&after_id=0&per_page=50`}
                    />
                </div>
            </div>

            <div className="min-w-0 space-y-2.5">
                <h3 className="font-medium text-xs sm:text-sm">3. Read the response</h3>
                <p className="text-muted-foreground text-xs leading-relaxed">
                    The API provides paginated JSON responses. Each row uses corrected data when it
                    is available; otherwise, <code className="text-xs">corrected_data</code>{' '}
                    contains the raw AI extraction. Read{' '}
                    <code className="text-xs">verification_status</code> to distinguish verified
                    from unverified data. A temporary, 60-minute signed URL is included in the{' '}
                    <code className="text-xs">source_file.url</code> field, allowing external
                    systems to download or preview the actual file. Uploader email and credentials
                    are excluded.
                </p>
                <CodeBlock>{responseExample}</CodeBlock>
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1">
                    <h3 className="font-medium text-xs sm:text-sm">4. Continue through pages</h3>
                    <p className="text-muted-foreground text-xs leading-normal">
                        Read <code>meta.next_after_id</code>. When <code>meta.has_more</code> is
                        true, send that value as the next <code>after_id</code>. Page size defaults
                        to 50 and is capped at 100.
                    </p>
                </div>
                <div className="space-y-1">
                    <h3 className="font-medium text-xs sm:text-sm">Responses and errors</h3>
                    <p className="text-muted-foreground text-xs leading-normal">
                        Results are returned in <code>data</code>. HTTP 401 means the key is
                        invalid, expired, revoked, or no longer authorized; 422 means a filter is
                        invalid; 429 means the rate limit was reached.
                    </p>
                </div>
            </div>

            <div className="rounded-lg bg-muted/40 p-3 text-xs">
                <p className="font-medium">Security checklist</p>
                <ul className="mt-1 list-inside list-disc space-y-1 text-[11px] text-muted-foreground leading-relaxed">
                    <li>Store the key only in a server-side secret manager.</li>
                    <li>Never embed it in browser JavaScript, mobile apps, logs, or URLs.</li>
                    <li>Use the shortest practical expiry and revoke unused keys immediately.</li>
                </ul>
            </div>
        </section>
    );
}

function CodeExamples({ endpoints }: { endpoints: ApiEndpoints }) {
    const [lang, setLang] = useState<'curl' | 'js' | 'php' | 'python'>('curl');

    const curlCode = `curl --request GET \\
  --url "${endpoints.serial}?serial_number=SN-14&after_id=0&per_page=50" \\
  --header "Accept: application/json" \\
  --header "Authorization: Bearer YOUR_API_KEY"`;

    const jsCode = `const response = await fetch(
  '${endpoints.serial}?serial_number=SN-14&after_id=0&per_page=50',
  {
    headers: {
      'Accept': 'application/json',
      'Authorization': 'Bearer YOUR_API_KEY',
    },
  }
);

const data = await response.json();
console.log(data.data);  // array of corrected documents`;

    const phpCode = `<?php
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => '${endpoints.serial}?serial_number=SN-14&after_id=0&per_page=50',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'Authorization: Bearer YOUR_API_KEY',
    ],
]);

$body = curl_exec($ch);
curl_close($ch);

$data = json_decode($body, true);
print_r($data['data']); // array of corrected documents`;

    const pythonCode = `import requests

response = requests.get(
    '${endpoints.serial}',
    params={'serial_number': 'SN-14', 'after_id': 0, 'per_page': 50},
    headers={
        'Accept': 'application/json',
        'Authorization': 'Bearer YOUR_API_KEY',
    },
)

data = response.json()
print(data['data'])  # list of corrected documents`;

    const codeMap = { curl: curlCode, js: jsCode, php: phpCode, python: pythonCode };
    const labels: Record<string, string> = {
        curl: 'cURL',
        js: 'JavaScript',
        php: 'PHP',
        python: 'Python',
    };

    return (
        <div className="min-w-0 overflow-hidden rounded-lg border">
            <div className="flex border-b bg-muted/30">
                {(['curl', 'js', 'php', 'python'] as const).map((l) => (
                    <button
                        key={l}
                        type="button"
                        onClick={() => setLang(l)}
                        className={cn(
                            '-mb-px border-b-2 px-3 py-1.5 font-medium text-[11px] transition-colors',
                            lang === l
                                ? 'border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {labels[l]}
                    </button>
                ))}
            </div>
            <CodeBlock className="rounded-none border-0">{codeMap[lang]}</CodeBlock>
        </div>
    );
}

function EndpointCard({
    title,
    description,
    url,
}: {
    title: string;
    description: string;
    url: string;
}) {
    return (
        <div className="w-full min-w-0 space-y-1.5 overflow-hidden rounded-lg border bg-muted/20 p-2.5">
            <div>
                <p className="font-medium text-xs sm:text-sm">{title}</p>
                <p className="text-[11px] text-muted-foreground leading-normal sm:text-xs">
                    {description}
                </p>
            </div>
            <CodeBlock>{`GET ${url}`}</CodeBlock>
        </div>
    );
}

function CodeBlock({ children, className }: { children: string; className?: string }) {
    return (
        <pre
            className={cn(
                'w-full overflow-x-auto rounded-lg border bg-slate-950 p-2.5 text-[11px] text-slate-100 leading-normal sm:text-xs',
                className,
            )}
        >
            <code>{children}</code>
        </pre>
    );
}

ApiKeys.layout = {
    breadcrumbs: [{ title: 'API keys', href: '/settings/api-keys' }],
};

function ApiTester({
    endpoints,
    defaultApiKey,
}: {
    endpoints: ApiEndpoints;
    defaultApiKey: string | null;
}) {
    const [apiKey, setApiKey] = useState(defaultApiKey || '');
    const [endpointType, setEndpointType] = useState<'serial' | 'poNumber'>('serial');
    const [queryParam, setQueryParam] = useState('');
    const [testing, setTesting] = useState(false);
    const [response, setResponse] = useState<string | null>(null);

    async function handleTest(e: React.FormEvent) {
        e.preventDefault();
        setTesting(true);
        setResponse(null);

        let url = `${endpoints[endpointType]}?after_id=0&per_page=10`;
        if (endpointType === 'serial' && queryParam) {
            url += `&serial_number=${encodeURIComponent(queryParam)}`;
        } else if (endpointType === 'poNumber' && queryParam) {
            url += `&po_number=${encodeURIComponent(queryParam)}`;
        }

        try {
            const res = await fetch(url, {
                headers: {
                    Accept: 'application/json',
                    Authorization: `Bearer ${apiKey}`,
                },
            });
            const data = await res.json();
            setResponse(JSON.stringify(data, null, 2));
        } catch (error: unknown) {
            setResponse(error instanceof Error ? error.message : 'Error occurred while testing.');
        } finally {
            setTesting(false);
        }
    }

    return (
        <section className="w-full min-w-0 space-y-4 overflow-hidden rounded-lg border p-3.5 sm:p-4">
            <div>
                <h2 className="font-semibold text-sm">API Tester</h2>
                <p className="text-muted-foreground text-xs leading-normal">
                    Paste an API key and test any of the available endpoints interactively.
                </p>
            </div>
            <form onSubmit={handleTest} className="space-y-4">
                <div className="grid max-w-md gap-1.5">
                    <Label htmlFor="api_key">API Key</Label>
                    <Input
                        id="api_key"
                        value={apiKey}
                        onChange={(e) => setApiKey(e.target.value)}
                        placeholder="Paste your API key here..."
                        required
                        className="h-[30px] font-mono text-xs"
                    />
                </div>
                <div className="grid max-w-md gap-1.5">
                    <Label htmlFor="endpoint_type">Endpoint</Label>
                    <Select
                        value={endpointType}
                        onValueChange={(value) => {
                            if (value !== 'serial' && value !== 'poNumber') return;

                            setEndpointType(value);
                            setQueryParam('');
                        }}
                    >
                        <SelectTrigger id="endpoint_type" className="h-[30px] text-xs">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="serial">By serial number</SelectItem>
                            <SelectItem value="poNumber">By PO number</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div className="grid max-w-md gap-1.5">
                    <Label htmlFor="query_param">
                        {endpointType === 'serial' ? 'Serial Number' : 'PO Number'}
                    </Label>
                    <Input
                        id="query_param"
                        value={queryParam}
                        onChange={(e) => setQueryParam(e.target.value)}
                        placeholder={endpointType === 'serial' ? 'e.g., SN-14' : 'e.g., 12345'}
                        required
                        className="h-[30px] text-xs"
                    />
                </div>
                <Button type="submit" disabled={testing || !apiKey} className="mt-2">
                    {testing ? (
                        <Loader2 className="mr-1.5 size-3.5 animate-spin" />
                    ) : (
                        <Play className="mr-1.5 size-3.5" />
                    )}
                    Send Request
                </Button>
            </form>

            {response && (
                <div className="mt-4 min-w-0">
                    <p className="mb-1.5 font-medium text-xs">Response</p>
                    <CodeBlock className="max-h-[400px] overflow-y-auto">{response}</CodeBlock>
                </div>
            )}
        </section>
    );
}
