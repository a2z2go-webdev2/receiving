export type Paginator<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type PendingArrival = {
    id: number;
    po_number: string | null;
    description: string;
    sku_number: string | null;
    ordered_quantity: number | null;
    supplier_delivered_quantity: number;
    unit: string | null;
    po_date: string | null;
    supplier_delivery_date: string | null;
    po_waiting_days: number | null;
    is_received?: boolean;
    received_at?: string | null;
    lot_number?: string | null;
};

export type PendingPoGroup = {
    po_number: string;
    item_count: number;
    pending_item_count: number;
    total_supplier_delivered_quantity: number;
    po_date: string | null;
    supplier_delivery_date: string | null;
    items: PendingArrival[];
};

export type InventoryItem = {
    id: number;
    sku_number: string | null;
    description: string;
    unit: string | null;
    received_quantity: number;
    allocated_quantity: number;
    available_quantity: number;
    lot_count: number;
    unknown_date_lots: number;
    oldest_received_at: string | null;
};

export type WarehouseItemOption = Pick<InventoryItem, 'id' | 'sku_number' | 'description' | 'unit'>;

export type DeliveryItemOption = Pick<
    InventoryItem,
    'id' | 'sku_number' | 'description' | 'unit' | 'available_quantity'
>;

export type DeliveryLine = {
    id: number;
    warehouse_item_id?: number;
    description: string;
    sku_number: string | null;
    quantity: number;
    unit: string | null;
};

export type Delivery = {
    id: number;
    customer_name: string;
    delivery_reference: string | null;
    sales_order: string | null;
    po: string | null;
    notes: string | null;
    status: 'draft' | 'dispatched' | 'delivered';
    dispatched_at?: string | null;
    delivered_at?: string | null;
    delivery_location?: string | null;
    delivered_by_email?: string | null;
    delivered_by_name?: string | null;
    created_at?: string;
    lines: DeliveryLine[];
};

export type TruckShipment = {
    shipment_reference: string;
    customer_count: number;
    customers_summary: string;
    status: 'draft' | 'dispatched' | 'delivered';
    created_at: string;
    dispatched_at: string | null;
    delivered_at: string | null;
    delivery_location?: string | null;
    delivered_by_email?: string | null;
    total_items_count: number;
    deliveries: Delivery[];
};
