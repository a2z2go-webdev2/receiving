import { Link, usePage } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    ClipboardCheck,
    ClipboardList,
    FileArchive,
    FileBarChart2,
    FileSpreadsheet,
    FileText,
    KeyRound,
    LayoutGrid,
    Mail,
    PackageCheck,
    Settings2,
    Truck,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import type { NavItem, NavSubItem } from '@/types';
import { Permission } from '@/types/enums/permission';

function navGroup(title: string, icon: LucideIcon, items: NavSubItem[]): NavItem | null {
    return items.length > 0 ? { title, icon, items } : null;
}

export function AppSidebar() {
    const { auth } = usePage().props;
    const permissions = auth.user?.permissions ?? [];

    const generalNavItems: NavItem[] = [
        {
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
        ...(permissions.includes(Permission.ViewUploads)
            ? [
                  { title: 'Receive logs', href: '/admin/uploads', icon: FileArchive },
                  { title: 'Purchase orders', href: '/admin/purchase-orders', icon: FileText },
                  {
                      title: 'PO item records',
                      href: '/admin/purchase-orders/items',
                      icon: ClipboardList,
                  },
                  {
                      title: 'Reports',
                      href: '/admin/purchase-orders/reports',
                      icon: FileBarChart2,
                  },
                  {
                      title: 'Google Sheets Sync',
                      href: '/admin/sheets-sync',
                      icon: FileSpreadsheet,
                  },
              ]
            : []),
        ...(permissions.includes(Permission.AccessWarehouse)
            ? [
                  {
                      title: 'Warehouse',
                      icon: PackageCheck,
                      items: [
                          {
                              title: 'Process overview',
                              href: '/warehouse/dashboard',
                              icon: LayoutGrid,
                          },
                          {
                              title: 'Confirm arrivals',
                              href: '/warehouse/arrivals',
                              icon: ClipboardCheck,
                          },
                          {
                              title: 'Inventory',
                              href: '/warehouse/inventory',
                              icon: PackageCheck,
                          },
                          {
                              title: 'Customer deliveries',
                              href: '/warehouse/deliveries',
                              icon: Truck,
                          },
                      ],
                  },
              ]
            : []),
    ];

    const adminNavItems: NavItem[] = [
        navGroup('People & access', Users, [
            ...(permissions.includes(Permission.ViewUsers)
                ? [{ title: 'Users', href: '/admin/users', icon: Users }]
                : []),
            ...(permissions.includes(Permission.ManageUploadAccess)
                ? [{ title: 'Upload access', href: '/admin/upload-access', icon: KeyRound }]
                : []),
            ...(permissions.includes(Permission.ManageRecipients)
                ? [{ title: 'Email recipients', href: '/admin/recipients', icon: Mail }]
                : []),
        ]),
        navGroup('System', Settings2, [
            ...(permissions.includes(Permission.ViewActivityLogs)
                ? [{ title: 'Activity logs', href: '/admin/activity', icon: Activity }]
                : []),
            ...(permissions.includes(Permission.ManageSettings)
                ? [
                      {
                          title: 'Upload lanes & Legacy Data',
                          href: '/admin/receiving-settings',
                          icon: Settings2,
                      },
                  ]
                : []),
        ]),
    ].filter((item): item is NavItem => item !== null);

    const showAdminSection = adminNavItems.length > 0;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={generalNavItems} label="General" />
                {showAdminSection && <NavMain items={adminNavItems} label="Administration" />}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
