import { Head, router, useForm } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock,
    Eye,
    FileText,
    Hash,
    LoaderCircle,
    LocateFixed,
    MapPin,
    Package,
    RefreshCw,
    Search,
    StickyNote,
    Truck,
    User,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type DeliveryItem = {
    id: number;
    warehouse_item_id: number;
    quantity: number | string;
    item?: {
        id: number;
        sku: string;
        name?: string;
        description?: string;
        uom?: string;
    };
};

type DeliveryData = {
    id: number;
    customer_name: string;
    delivery_reference: string | null;
    sales_order: string | null;
    po: string | null;
    status: string;
    dispatched_at: string | null;
    delivered_at: string | null;
    delivery_location: string | null;
    created_at: string;
    notes: string | null;
    dispatched_by?: { name: string } | null;
    delivered_by?: { name: string } | null;
    lines: DeliveryItem[];
};

type SuggestionItem = {
    id: number;
    customer_name: string;
    sales_order: string | null;
    po: string | null;
    status: 'dispatched' | 'delivered';
    delivery_reference: string | null;
};

function formatDateLong(dateStr: string | null | undefined): string {
    if (!dateStr) return 'N/A';
    const date = new Date(dateStr);
    if (Number.isNaN(date.getTime())) return dateStr;
    return date.toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
}

type GPSLocation = {
    latitude: number;
    longitude: number;
    accuracy: number;
};

export default function DriverDashboard({
    search,
    delivery,
}: {
    search: string | null;
    delivery: DeliveryData | null;
}) {
    const [searchValue, setSearchValue] = useState(search || '');
    const [suggestions, setSuggestions] = useState<SuggestionItem[]>([]);
    const [isLoadingSuggestions, setIsLoadingSuggestions] = useState(false);
    const [showDropdown, setShowDropdown] = useState(false);
    const searchRef = useRef<HTMLDivElement>(null);

    const [location, setLocation] = useState<GPSLocation | null>(null);
    const [locating, setLocating] = useState(false);
    const [locationError, setLocationError] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [showLocationModal, setShowLocationModal] = useState(() => {
        return localStorage.getItem('driverLocationModalSeen') !== 'true';
    });
    const [showDetailsModal, setShowDetailsModal] = useState(false);
    const [showGpsCard, setShowGpsCard] = useState(false);

    const isDelivered = delivery?.status === 'delivered';

    const { processing } = useForm();

    const captureLocation = useCallback(() => {
        if (!('geolocation' in navigator)) {
            setLocationError('Geolocation not supported on this device');
            return;
        }

        setLocating(true);
        setLocationError('');

        navigator.geolocation.getCurrentPosition(
            (position) => {
                setLocation({
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                });
                setLocating(false);
            },
            (err) => {
                let msg = 'GPS unavailable';
                if (err.code === err.PERMISSION_DENIED) {
                    msg = 'GPS permission denied';
                }
                setLocationError(msg);
                setLocation(null);
                setLocating(false);
            },
            {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 30000,
            },
        );
    }, []);

    useEffect(() => {
        if (!isDelivered && localStorage.getItem('driverLocationModalSeen') === 'true') {
            captureLocation();
        }
    }, [isDelivered, captureLocation]);

    const allowLocationModal = () => {
        localStorage.setItem('driverLocationModalSeen', 'true');
        setShowLocationModal(false);
        captureLocation();
    };

    useEffect(() => {
        if (!searchValue.trim()) {
            setSuggestions([]);
            setShowDropdown(false);
            return;
        }

        const timer = setTimeout(async () => {
            setIsLoadingSuggestions(true);
            try {
                const res = await fetch(
                    `/driver/suggestions?query=${encodeURIComponent(searchValue.trim())}`,
                );
                if (res.ok) {
                    const data = await res.json();
                    setSuggestions(data);
                    setShowDropdown(true);
                }
            } catch {
                setSuggestions([]);
            } finally {
                setIsLoadingSuggestions(false);
            }
        }, 200);

        return () => clearTimeout(timer);
    }, [searchValue]);

    useEffect(() => {
        function handleClickOutside(event: MouseEvent) {
            if (searchRef.current && !searchRef.current.contains(event.target as Node)) {
                setShowDropdown(false);
            }
        }
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const selectSuggestion = (item: SuggestionItem) => {
        const searchTerm = item.customer_name || item.sales_order || item.po || '';
        setSearchValue(searchTerm);
        setShowDropdown(false);
        router.get('/driver/dashboard', { search: searchTerm }, { preserveState: true });
    };

    const handleClearSearch = () => {
        setSearchValue('');
        setSuggestions([]);
        setShowDropdown(false);
        router.get('/driver/dashboard', {}, { preserveState: true });
    };

    const handleOpenConfirm = (e: React.FormEvent) => {
        e.preventDefault();
        if (!delivery || isDelivered) return;
        setConfirming(true);
    };

    const submitDelivery = () => {
        if (!delivery || isDelivered) return;

        const locationString = location
            ? `${location.latitude.toFixed(6)}, ${location.longitude.toFixed(6)}`
            : 'Unknown location';

        router.post(
            `/driver/deliveries/${delivery.id}/deliver`,
            {
                delivery_location: locationString,
            },
            {
                preserveState: false,
                preserveScroll: false,
                onFinish: () => setConfirming(false),
            },
        );
    };

    return (
        <>
            <Head title="Driver Deliveries" />

            <div className="mx-auto w-full min-w-0 max-w-xl space-y-2.5 p-2 sm:p-4">
                {/* Global GPS Location Status Indicator */}
                {!isDelivered && (
                    <div className="fixed right-4 bottom-4 z-50 flex flex-col items-end gap-2">
                        {/* Show full card if no delivery is selected OR if user toggled showGpsCard */}
                        {(!delivery || showGpsCard) && (
                            <Card className="w-[220px] border-border/60 bg-background/95 p-1 shadow-xl backdrop-blur supports-[backdrop-filter]:bg-background/80 sm:w-[280px]">
                                <CardContent className="p-1.5 sm:p-2.5">
                                    {delivery && (
                                        <div className="mb-1 flex items-center justify-between border-b pb-1 font-semibold text-[11px] text-muted-foreground">
                                            <span>GPS Location</span>
                                            <button
                                                type="button"
                                                onClick={() => setShowGpsCard(false)}
                                                className="text-muted-foreground hover:text-foreground"
                                            >
                                                <X className="size-3.5" />
                                            </button>
                                        </div>
                                    )}
                                    {locating ? (
                                        <div className="flex items-center gap-2 text-[11px] text-blue-700">
                                            <LoaderCircle className="size-3.5 shrink-0 animate-spin text-blue-600" />
                                            <span className="font-medium">
                                                Acquiring GPS location…
                                            </span>
                                        </div>
                                    ) : location ? (
                                        <div className="flex flex-col gap-2">
                                            <div className="relative h-20 w-full overflow-hidden rounded-md border bg-muted sm:h-40">
                                                <iframe
                                                    title="Location Map"
                                                    width="100%"
                                                    height="100%"
                                                    frameBorder="0"
                                                    scrolling="no"
                                                    src={`https://maps.google.com/maps?q=${encodeURIComponent(`${location.latitude},${location.longitude}`)}&z=15&output=embed`}
                                                    className="border-0"
                                                    loading="lazy"
                                                    referrerPolicy="no-referrer-when-downgrade"
                                                />
                                            </div>
                                            <div className="flex items-center justify-between gap-1.5 text-[11px] text-emerald-900">
                                                <div className="flex min-w-0 items-center gap-1.5">
                                                    <MapPin className="size-3.5 shrink-0 text-emerald-600" />
                                                    <div className="flex min-w-0 flex-col">
                                                        <span className="min-w-0 truncate font-medium">
                                                            GPS Ready (~
                                                            {Math.round(location.accuracy)}m)
                                                        </span>
                                                        <span className="font-mono text-[9px] opacity-80">
                                                            {location.latitude.toFixed(6)},{' '}
                                                            {location.longitude.toFixed(6)}
                                                        </span>
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={captureLocation}
                                                    className="flex shrink-0 items-center text-[10px] text-emerald-800 hover:underline"
                                                    title="Refresh location"
                                                >
                                                    <RefreshCw className="mr-0.5 size-3" /> Refresh
                                                </button>
                                            </div>
                                        </div>
                                    ) : (
                                        <div className="flex items-center justify-between gap-1.5 text-[11px] text-muted-foreground">
                                            <div className="flex min-w-0 items-center gap-1.5">
                                                <LocateFixed className="size-3.5 shrink-0 text-amber-600" />
                                                <span className="min-w-0 truncate">
                                                    {locationError ||
                                                        'GPS unavailable. Will save as "Unknown location".'}
                                                </span>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={captureLocation}
                                                className="flex shrink-0 items-center text-[10px] text-foreground hover:underline"
                                            >
                                                <RefreshCw className="mr-0.5 size-3" /> Retry
                                            </button>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {/* Floating Icon Button (Shown when delivery details are displayed) */}
                        {delivery && (
                            <Button
                                type="button"
                                size="icon"
                                variant={showGpsCard ? 'secondary' : 'default'}
                                className="relative size-11 rounded-full border-2 border-background shadow-xl"
                                onClick={() => setShowGpsCard((prev) => !prev)}
                                title="GPS Location Map"
                            >
                                <MapPin className="size-5" />
                                {location ? (
                                    <span className="absolute top-0 right-0 size-3 rounded-full bg-emerald-500 ring-2 ring-background" />
                                ) : locating ? (
                                    <span className="absolute top-0 right-0 size-3 animate-ping rounded-full bg-blue-500 ring-2 ring-background" />
                                ) : (
                                    <span className="absolute top-0 right-0 size-3 rounded-full bg-amber-500 ring-2 ring-background" />
                                )}
                            </Button>
                        )}
                    </div>
                )}

                {/* Search Bar - Auto-Suggest (Customer Name, SO, PO) */}
                <Card className="border-border/60 shadow-none">
                    <CardContent className="px-2.5 py-1.5">
                        <div ref={searchRef} className="relative w-full min-w-0">
                            <Search className="absolute top-2 left-2.5 size-3.5 text-muted-foreground" />
                            <Input
                                type="text"
                                placeholder="Search Customer Name, Sales Order, or PO..."
                                className="h-7 pr-7 pl-8 text-xs"
                                value={searchValue}
                                onFocus={() => {
                                    if (suggestions.length > 0) setShowDropdown(true);
                                }}
                                onChange={(e) => setSearchValue(e.target.value)}
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter') {
                                        e.preventDefault();
                                        setShowDropdown(false);
                                        if (searchValue.trim()) {
                                            router.get(
                                                '/driver/dashboard',
                                                { search: searchValue.trim() },
                                                { preserveState: true },
                                            );
                                        }
                                    }
                                }}
                            />
                            {isLoadingSuggestions ? (
                                <LoaderCircle className="absolute top-2 right-2 size-3.5 animate-spin text-muted-foreground" />
                            ) : searchValue ? (
                                <button
                                    type="button"
                                    onClick={handleClearSearch}
                                    className="absolute top-2 right-2 text-muted-foreground hover:text-foreground"
                                >
                                    <X className="size-3.5" />
                                </button>
                            ) : null}

                            {/* Auto-Suggest Dropdown */}
                            {showDropdown && (
                                <div className="absolute top-full right-0 left-0 z-50 mt-1 max-h-60 overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-md">
                                    {suggestions.length > 0 ? (
                                        <div className="space-y-0.5">
                                            <div className="px-2 py-1 font-semibold text-[10px] text-muted-foreground uppercase tracking-wider">
                                                Matching Dispatched / Delivered (
                                                {suggestions.length})
                                            </div>
                                            {suggestions.map((item) => (
                                                <button
                                                    type="button"
                                                    key={item.id}
                                                    className="flex w-full cursor-pointer items-center justify-between rounded-sm px-2 py-1.5 text-left text-xs transition-colors hover:bg-accent hover:text-accent-foreground"
                                                    onClick={() => selectSuggestion(item)}
                                                >
                                                    <div className="flex min-w-0 flex-col">
                                                        <span className="truncate font-semibold">
                                                            {item.customer_name}
                                                        </span>
                                                        <span className="text-[10px] text-muted-foreground">
                                                            {[
                                                                item.sales_order &&
                                                                    `SO: ${item.sales_order}`,
                                                                item.po && `PO: ${item.po}`,
                                                            ]
                                                                .filter(Boolean)
                                                                .join(' • ')}
                                                        </span>
                                                    </div>
                                                    <Badge
                                                        className={cn(
                                                            'h-4 px-1.5 font-normal text-[9px] capitalize',
                                                            item.status === 'dispatched'
                                                                ? 'bg-blue-600'
                                                                : 'bg-emerald-600',
                                                        )}
                                                    >
                                                        {item.status}
                                                    </Badge>
                                                </button>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="p-3 text-center text-muted-foreground text-xs">
                                            No dispatched or delivered records matching "
                                            {searchValue}"
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Search Active but No Result */}
                {search && !delivery && (
                    <Card className="border-dashed bg-muted/20">
                        <CardContent className="flex flex-col items-center justify-center py-6 text-center">
                            <Truck className="mb-2 size-8 text-muted-foreground/40" />
                            <h3 className="font-medium text-sm">No delivery found</h3>
                            <p className="mt-0.5 text-[11px] text-muted-foreground">
                                No delivery matches "{search}". Please re-check the reference
                                number.
                            </p>
                        </CardContent>
                    </Card>
                )}

                {/* Active Dispatched or Delivered Delivery Info */}
                {delivery && (
                    <Card
                        className={`overflow-hidden border-2 shadow-xs ${isDelivered ? 'border-emerald-200' : 'border-primary/20'}`}
                    >
                        {/* Compact Header */}
                        <div className="flex items-center justify-between gap-2 border-b bg-muted/40 px-2.5 py-1.5">
                            <div className="flex min-w-0 items-center gap-1.5">
                                <Package
                                    className={`size-4 shrink-0 ${isDelivered ? 'text-emerald-600' : 'text-primary'}`}
                                />
                                <span className="min-w-0 truncate font-bold text-sm">
                                    Delivery #{delivery.delivery_reference || delivery.id}
                                </span>
                            </div>
                            {isDelivered ? (
                                <Badge
                                    variant="outline"
                                    className="border-emerald-300 bg-emerald-50 px-1.5 py-0 font-medium text-[10px] text-emerald-800"
                                >
                                    Delivered
                                </Badge>
                            ) : (
                                <Badge
                                    variant="outline"
                                    className="border-blue-300 bg-blue-50 px-1.5 py-0 text-[10px] text-blue-700"
                                >
                                    Dispatched
                                </Badge>
                            )}
                        </div>

                        <CardContent className="space-y-2 p-2 sm:p-2.5">
                            {/* Delivered Status Alert Banner if already delivered */}
                            {isDelivered && (
                                <div className="flex items-start gap-2.5 rounded-md border border-emerald-300 bg-emerald-50 p-2 text-emerald-900 text-xs">
                                    <CheckCircle2 className="mt-0.5 size-4 shrink-0 text-emerald-600" />
                                    <div>
                                        <p className="font-semibold text-emerald-950 text-xs">
                                            Already Delivered to Customer
                                        </p>
                                        <p className="mt-0.5 text-[11px] text-emerald-800">
                                            Marked delivered
                                            {delivery.delivered_at
                                                ? ` on ${formatDateLong(delivery.delivered_at)}`
                                                : ''}
                                            {delivery.delivery_location
                                                ? ` at ${delivery.delivery_location}`
                                                : ''}
                                            {delivery.delivered_by?.name
                                                ? ` by ${delivery.delivered_by.name}`
                                                : ''}
                                            .
                                        </p>
                                    </div>
                                </div>
                            )}

                            {/* Key Delivery Metadata Grid - Compact 4 Cols */}
                            <div className="grid grid-cols-2 gap-1.5 rounded-md border bg-muted/10 p-1.5 text-[11px] sm:grid-cols-4">
                                <div className="min-w-0">
                                    <span className="flex items-center gap-1 font-medium text-[10px] text-muted-foreground">
                                        <User className="size-3" /> Customer
                                    </span>
                                    <p
                                        className="truncate font-semibold text-foreground text-xs"
                                        title={delivery.customer_name}
                                    >
                                        {delivery.customer_name || 'N/A'}
                                    </p>
                                </div>
                                <div className="min-w-0">
                                    <span className="flex items-center gap-1 font-medium text-[10px] text-muted-foreground">
                                        <FileText className="size-3" /> Sales Order
                                    </span>
                                    <p className="truncate font-semibold text-foreground text-xs">
                                        {delivery.sales_order || 'N/A'}
                                    </p>
                                </div>
                                <div className="min-w-0">
                                    <span className="flex items-center gap-1 font-medium text-[10px] text-muted-foreground">
                                        <Hash className="size-3" /> Customer PO
                                    </span>
                                    <p className="truncate font-semibold text-foreground text-xs">
                                        {delivery.po || 'N/A'}
                                    </p>
                                </div>
                                <div className="min-w-0">
                                    <span className="flex items-center gap-1 font-medium text-[10px] text-muted-foreground">
                                        <Clock className="size-3" />{' '}
                                        {isDelivered ? 'Delivered Date' : 'Dispatched Date'}
                                    </span>
                                    <p className="truncate font-semibold text-foreground text-xs">
                                        {isDelivered && delivery.delivered_at
                                            ? formatDateLong(delivery.delivered_at)
                                            : delivery.dispatched_at
                                              ? formatDateLong(delivery.dispatched_at)
                                              : 'N/A'}
                                    </p>
                                </div>
                            </div>

                            {/* Dispatch Notes (if any) */}
                            {delivery.notes && (
                                <div className="flex items-start gap-1.5 rounded border border-amber-200 bg-amber-50/70 p-1.5 text-[11px] text-amber-900">
                                    <StickyNote className="mt-0.5 size-3.5 shrink-0 text-amber-600" />
                                    <div className="min-w-0 flex-1">
                                        <span className="font-semibold">Notes: </span>
                                        {delivery.notes}
                                    </div>
                                </div>
                            )}

                            {/* Delivery Line Items - Compact Scrollable List */}
                            <div className="overflow-hidden rounded border bg-card">
                                <div className="flex items-center justify-between border-b bg-muted/20 px-2.5 py-1 font-semibold text-[11px] text-muted-foreground">
                                    <span>Items Delivered ({delivery.lines?.length || 0})</span>
                                </div>
                                <div className="max-h-44 divide-y overflow-y-auto">
                                    {delivery.lines?.map((line, lineIdx) => (
                                        <div
                                            key={line.id}
                                            className="flex items-start justify-between gap-2 px-2.5 py-1.5 text-xs"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="break-words font-medium text-[11px] text-foreground leading-snug">
                                                    {line.item?.name ||
                                                        line.item?.description ||
                                                        `Item #${lineIdx + 1}`}
                                                </p>
                                                {line.item?.sku && (
                                                    <p className="font-mono text-[10px] text-muted-foreground">
                                                        SKU: {line.item.sku}
                                                    </p>
                                                )}
                                            </div>
                                            <Badge
                                                variant="secondary"
                                                className="shrink-0 self-start px-1.5 py-0 font-mono text-[10px]"
                                            >
                                                Qty: {Number(line.quantity)} {line.item?.uom || ''}
                                            </Badge>
                                        </div>
                                    ))}
                                    {(!delivery.lines || delivery.lines.length === 0) && (
                                        <div className="p-2 text-center text-[11px] text-muted-foreground italic">
                                            No items listed
                                        </div>
                                    )}
                                </div>
                            </div>

                            {/* Submit Button or Already Delivered State */}
                            <div className="flex flex-col gap-1.5 pt-1">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className="h-9 w-full font-semibold text-xs"
                                    onClick={() => setShowDetailsModal(true)}
                                >
                                    <Eye className="mr-1.5 size-4" /> View Full Details
                                </Button>

                                {isDelivered ? (
                                    <Button
                                        disabled
                                        className="h-9 w-full cursor-default bg-emerald-700 font-semibold text-white text-xs opacity-90"
                                    >
                                        <CheckCircle2 className="mr-1.5 size-4" /> Already Delivered
                                    </Button>
                                ) : (
                                    <form onSubmit={handleOpenConfirm}>
                                        <Button
                                            type="submit"
                                            className="h-9 w-full font-semibold text-xs shadow-xs"
                                            disabled={processing}
                                        >
                                            {processing ? (
                                                <>
                                                    <LoaderCircle className="mr-1.5 size-4 animate-spin" />{' '}
                                                    Submitting…
                                                </>
                                            ) : (
                                                <>
                                                    <CheckCircle2 className="mr-1.5 size-4" /> Mark
                                                    as Delivered
                                                </>
                                            )}
                                        </Button>
                                    </form>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>

            {/* Full Details Modal */}
            <Dialog open={showDetailsModal} onOpenChange={setShowDetailsModal}>
                <DialogContent className="max-w-md overflow-hidden p-0">
                    <DialogHeader className="border-b bg-muted/40 p-4">
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <FileText className="size-5 text-primary" />
                            Delivery Full Details
                        </DialogTitle>
                    </DialogHeader>

                    {delivery && (
                        <div className="max-h-[70vh] space-y-5 overflow-y-auto p-4">
                            {/* Customer Info */}
                            <div className="space-y-2 text-sm">
                                <h4 className="flex items-center gap-1.5 font-semibold text-muted-foreground text-xs uppercase tracking-wider">
                                    <User className="size-3.5" /> Customer Info
                                </h4>
                                <div className="space-y-2 rounded-lg border bg-card p-3">
                                    <div className="flex flex-col">
                                        <span className="font-medium text-[10px] text-muted-foreground">
                                            Customer Name
                                        </span>
                                        <span className="font-medium text-foreground">
                                            {delivery.customer_name || 'N/A'}
                                        </span>
                                    </div>
                                    <div className="grid grid-cols-2 gap-4">
                                        <div className="flex flex-col">
                                            <span className="font-medium text-[10px] text-muted-foreground">
                                                Customer PO
                                            </span>
                                            <span className="font-medium text-foreground">
                                                {delivery.po || 'N/A'}
                                            </span>
                                        </div>
                                        <div className="flex flex-col">
                                            <span className="font-medium text-[10px] text-muted-foreground">
                                                Sales Order
                                            </span>
                                            <span className="font-medium text-foreground">
                                                {delivery.sales_order || 'N/A'}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* Dispatch Info */}
                            <div className="space-y-2 text-sm">
                                <h4 className="flex items-center gap-1.5 font-semibold text-muted-foreground text-xs uppercase tracking-wider">
                                    <Clock className="size-3.5" /> Dispatch Info
                                </h4>
                                <div className="grid grid-cols-2 gap-4 rounded-lg border bg-card p-3">
                                    <div className="flex flex-col">
                                        <span className="font-medium text-[10px] text-muted-foreground">
                                            Dispatched Date
                                        </span>
                                        <span className="font-medium text-foreground">
                                            {formatDateLong(delivery.dispatched_at)}
                                        </span>
                                    </div>
                                    <div className="flex flex-col">
                                        <span className="font-medium text-[10px] text-muted-foreground">
                                            Delivered Date
                                        </span>
                                        <span className="font-medium text-foreground">
                                            {formatDateLong(delivery.delivered_at)}
                                        </span>
                                    </div>
                                </div>
                            </div>

                            {/* Delivery Location */}
                            {delivery.delivery_location &&
                                delivery.delivery_location !== 'Unknown location' &&
                                (() => {
                                    const [lat, lng] = delivery.delivery_location
                                        .split(',')
                                        .map((s) => s.trim());
                                    if (lat && lng) {
                                        return (
                                            <div className="space-y-2 text-sm">
                                                <h4 className="flex items-center gap-1.5 font-semibold text-muted-foreground text-xs uppercase tracking-wider">
                                                    <MapPin className="size-3.5" /> Delivery
                                                    Location
                                                </h4>
                                                <div className="flex flex-col gap-2 rounded-lg border bg-card p-3">
                                                    <div className="relative h-24 w-full overflow-hidden rounded-md border sm:h-48">
                                                        <iframe
                                                            title="Delivery Location"
                                                            width="100%"
                                                            height="100%"
                                                            frameBorder="0"
                                                            scrolling="no"
                                                            src={`https://maps.google.com/maps?q=${lat},${lng}&output=embed`}
                                                        />
                                                    </div>
                                                    <a
                                                        href={`https://www.google.com/maps?q=${lat},${lng}`}
                                                        target="_blank"
                                                        rel="noreferrer"
                                                        className="flex items-center justify-center gap-1.5 rounded-md bg-blue-50 py-2 font-medium text-blue-700 text-xs hover:bg-blue-100 hover:underline"
                                                    >
                                                        <MapPin className="size-3.5" /> Open in
                                                        Google Maps
                                                    </a>
                                                    <div className="text-center font-mono text-[10px] text-muted-foreground">
                                                        {lat}, {lng}
                                                    </div>
                                                </div>
                                            </div>
                                        );
                                    }
                                    return null;
                                })()}

                            {/* Notes */}
                            {delivery.notes && (
                                <div className="space-y-2 text-sm">
                                    <h4 className="flex items-center gap-1.5 font-semibold text-muted-foreground text-xs uppercase tracking-wider">
                                        <StickyNote className="size-3.5" /> Notes
                                    </h4>
                                    <div className="whitespace-pre-wrap rounded-lg border border-amber-200 bg-amber-50 p-3 text-amber-900 text-sm">
                                        {delivery.notes}
                                    </div>
                                </div>
                            )}

                            {/* Line Items */}
                            <div className="space-y-2 text-sm">
                                <h4 className="flex items-center justify-between font-semibold text-muted-foreground text-xs uppercase tracking-wider">
                                    <span className="flex items-center gap-1.5">
                                        <Package className="size-3.5" /> Items
                                    </span>
                                    <Badge variant="secondary" className="font-mono text-[10px]">
                                        {delivery.lines?.length || 0}
                                    </Badge>
                                </h4>
                                <div className="divide-y overflow-hidden rounded-lg border">
                                    {delivery.lines?.map((line, lineIdx) => (
                                        <div
                                            key={line.id}
                                            className="flex items-start justify-between gap-4 bg-card p-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="break-words font-semibold text-foreground text-sm leading-snug">
                                                    {line.item?.name ||
                                                        line.item?.description ||
                                                        `Item #${lineIdx + 1}`}
                                                </p>
                                                {line.item?.sku && (
                                                    <p className="mt-0.5 font-mono text-muted-foreground text-xs">
                                                        SKU: {line.item.sku}
                                                    </p>
                                                )}
                                            </div>
                                            <Badge
                                                variant="outline"
                                                className="shrink-0 bg-muted/30 font-mono"
                                            >
                                                Qty: {Number(line.quantity)} {line.item?.uom || ''}
                                            </Badge>
                                        </div>
                                    ))}
                                    {(!delivery.lines || delivery.lines.length === 0) && (
                                        <div className="bg-card p-4 text-center text-muted-foreground text-sm italic">
                                            No items listed
                                        </div>
                                    )}
                                </div>
                            </div>
                        </div>
                    )}

                    <DialogFooter className="border-t bg-muted/20 p-4">
                        <Button
                            variant="outline"
                            onClick={() => setShowDetailsModal(false)}
                            className="w-full sm:w-auto"
                        >
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Optional Location Permission Request Dialog (Like Upload Page) */}
            <Dialog
                open={showLocationModal}
                onOpenChange={(open) => {
                    if (!open) {
                        localStorage.setItem('driverLocationModalSeen', 'true');
                        setShowLocationModal(false);
                    }
                }}
            >
                <DialogContent className="max-w-md p-4">
                    <DialogHeader>
                        <div className="mb-1 flex size-10 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <LocateFixed className="size-5" />
                        </div>
                        <DialogTitle className="text-base">
                            Share your location for deliveries?
                        </DialogTitle>
                        <DialogDescription className="text-xs">
                            Sharing your location records where items were delivered to customers.
                            It is optional — if you decline, it will automatically record as
                            "Unknown location".
                        </DialogDescription>
                    </DialogHeader>

                    <div className="rounded-lg bg-muted/40 p-2.5 text-xs">
                        <p className="font-medium text-foreground">If you choose to share:</p>
                        <ul className="mt-1 list-inside list-disc space-y-1 text-[11px] text-muted-foreground">
                            <li>Turn on Location Services or GPS for this device.</li>
                            <li>Allow location access when your browser prompts.</li>
                            <li>Move outdoors or near a window if accuracy is low.</li>
                        </ul>
                    </div>

                    {locationError && (
                        <p
                            role="alert"
                            className="rounded-md border border-red-200 bg-red-50 p-2 text-red-900 text-xs"
                        >
                            {locationError}
                        </p>
                    )}

                    <DialogFooter className="mt-2 gap-2 sm:gap-0">
                        <Button
                            type="button"
                            size="sm"
                            disabled={locating}
                            onClick={allowLocationModal}
                        >
                            {locating ? (
                                <LoaderCircle className="mr-1.5 size-3.5 animate-spin" />
                            ) : (
                                <MapPin className="mr-1.5 size-3.5" />
                            )}
                            {locating ? 'Finding location...' : 'Allow location'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* Confirmation Dialog */}
            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="max-w-md p-4">
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2 text-base">
                            <CheckCircle2 className="size-5 text-emerald-600" />
                            Confirm Delivery
                        </DialogTitle>
                        <DialogDescription className="text-xs">
                            Are you sure you want to mark this delivery as completed?
                        </DialogDescription>
                    </DialogHeader>

                    {delivery && (
                        <div className="space-y-2 rounded-lg border bg-muted/20 p-3 text-xs">
                            <div className="flex justify-between">
                                <span className="font-medium text-muted-foreground">
                                    Delivery Ref:
                                </span>
                                <span className="font-semibold">
                                    {delivery.delivery_reference || `#${delivery.id}`}
                                </span>
                            </div>
                            <div className="flex justify-between">
                                <span className="font-medium text-muted-foreground">Customer:</span>
                                <span className="font-semibold">
                                    {delivery.customer_name || 'N/A'}
                                </span>
                            </div>
                            {delivery.sales_order && (
                                <div className="flex justify-between">
                                    <span className="font-medium text-muted-foreground">
                                        Sales Order:
                                    </span>
                                    <span className="font-semibold">{delivery.sales_order}</span>
                                </div>
                            )}
                            {delivery.po && (
                                <div className="flex justify-between">
                                    <span className="font-medium text-muted-foreground">
                                        Customer PO:
                                    </span>
                                    <span className="font-semibold">{delivery.po}</span>
                                </div>
                            )}
                            <div className="flex justify-between border-t pt-2">
                                <span className="font-medium text-muted-foreground">
                                    Recorded Location:
                                </span>
                                <span className="font-semibold text-emerald-700">
                                    {location
                                        ? `GPS (${location.latitude.toFixed(4)}, ${location.longitude.toFixed(4)})`
                                        : 'Unknown location'}
                                </span>
                            </div>
                        </div>
                    )}

                    <DialogFooter className="mt-2 gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => setConfirming(false)}
                            disabled={processing}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            onClick={submitDelivery}
                            disabled={processing}
                            className="bg-emerald-600 text-white hover:bg-emerald-700"
                        >
                            {processing ? (
                                <>
                                    <LoaderCircle className="mr-1.5 size-3.5 animate-spin" />{' '}
                                    Confirming…
                                </>
                            ) : (
                                <>
                                    <CheckCircle2 className="mr-1.5 size-3.5" /> Confirm & Deliver
                                </>
                            )}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
