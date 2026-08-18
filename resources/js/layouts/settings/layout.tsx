import type { PageProps } from '@inertiajs/core';
import { Link, usePage } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { edit } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavLinkItem } from '@/types';
import { Permission } from '@/types/enums/permission';

type SettingsNavItem = NavLinkItem & {
    items?: { title: string; href: string; tab: string }[];
};

const sidebarNavItems: SettingsNavItem[] = [
    {
        title: 'Profile',
        href: edit(),
        icon: null,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const { auth } = usePage<PageProps>().props;
    const { url } = usePage();
    const navItems = auth.user.permissions.includes(Permission.ViewUploads)
        ? [
              ...sidebarNavItems,
              {
                  title: 'API keys',
                  href: '/settings/api-keys',
                  icon: null,
                  items: [
                      { title: 'Keys Manager', href: '/settings/api-keys?tab=keys', tab: 'keys' },
                      {
                          title: 'Integration Guide',
                          href: '/settings/api-keys?tab=guide',
                          tab: 'guide',
                      },
                  ],
              },
          ]
        : sidebarNavItems;

    return (
        <div className="px-3 py-3 md:py-4">
            <Heading title="Settings" description="Manage your profile and account settings" />

            <div className="flex flex-col lg:flex-row lg:space-x-8">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-x-0 space-y-0.5" aria-label="Settings">
                        {navItems.map((item) => {
                            const isActive = isCurrentOrParentUrl(item.href);
                            const hasSubItems = 'items' in item && item.items;

                            return (
                                <div key={toUrl(item.href)} className="space-y-1">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        asChild
                                        className={cn('h-7 w-full justify-start px-2 text-[11px]', {
                                            'bg-muted font-medium': isActive && !hasSubItems,
                                        })}
                                    >
                                        <Link href={item.href}>
                                            {item.icon && <item.icon className="h-4 w-4" />}
                                            {item.title}
                                        </Link>
                                    </Button>
                                    {item.items && isActive && (
                                        <div className="ml-2.5 flex flex-col space-y-0.5 border-l pl-4">
                                            {item.items.map((subItem) => {
                                                const isSubActive =
                                                    url.includes(`tab=${subItem.tab}`) ||
                                                    (subItem.tab === 'keys' &&
                                                        !url.includes('tab='));
                                                return (
                                                    <Button
                                                        key={subItem.tab}
                                                        size="sm"
                                                        variant="ghost"
                                                        asChild
                                                        className={cn(
                                                            'h-6 w-full justify-start px-1.5 font-normal text-[10px] text-muted-foreground hover:text-foreground',
                                                            isSubActive &&
                                                                'bg-muted/60 font-medium text-foreground',
                                                        )}
                                                    >
                                                        <Link href={subItem.href} preserveScroll>
                                                            {subItem.title}
                                                        </Link>
                                                    </Button>
                                                );
                                            })}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </nav>
                </aside>

                <Separator className="my-4 lg:hidden" />

                <div className="flex-1 md:max-w-2xl">
                    <section className="max-w-xl space-y-4">{children}</section>
                </div>
            </div>
        </div>
    );
}
