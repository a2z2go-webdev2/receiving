import type { ComponentProps } from 'react';
import UploadsIndex from '@/pages/admin/uploads/index';

export default function PurchaseOrdersIndex(props: ComponentProps<typeof UploadsIndex>) {
    return <UploadsIndex {...props} />;
}

PurchaseOrdersIndex.layout = {
    breadcrumbs: [{ title: 'Purchase orders', href: '/admin/purchase-orders' }],
};
