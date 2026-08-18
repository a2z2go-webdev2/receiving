import { Link, usePage } from '@inertiajs/react';
import { Files, UploadCloud } from 'lucide-react';
import { createContext, type ReactNode, useState } from 'react';
import AppLogo from '@/components/app-logo';
import { SessionExpiredDialog } from '@/components/session-expired-dialog';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';

export const ReceivingLayoutContext = createContext<{
    setHeaderContent: (content: ReactNode) => void;
}>({
    setHeaderContent: () => {},
});

type UploadType = { name: string; slug: string };

function isUploadType(value: unknown): value is UploadType {
    return (
        typeof value === 'object' &&
        value !== null &&
        'name' in value &&
        typeof value.name === 'string' &&
        'slug' in value &&
        typeof value.slug === 'string'
    );
}

export default function ReceivingLayout({ children }: { children: React.ReactNode }) {
    const page = usePage();
    const [headerContent, setHeaderContent] = useState<ReactNode>(null);
    const getInitials = useInitials();
    const uploadType = isUploadType(page.props.uploadType) ? page.props.uploadType : null;
    const isOtpPage = page.component === 'upload/otp' || page.component === 'admin/otp';
    const isUnavailablePage = page.component === 'upload/unavailable';
    const showHeader = !isOtpPage;
    const isHistory = uploadType
        ? page.url.startsWith(`/upload/${uploadType.slug}/uploads`)
        : false;
    const laneUrl = uploadType ? `/upload/${uploadType.slug}` : '/dashboard';

    return (
        <ReceivingLayoutContext.Provider value={{ setHeaderContent }}>
            <div className="flex h-screen flex-col overflow-hidden bg-background">
                {showHeader && (
                    <header className="z-40 shrink-0 border-b bg-background/95 backdrop-blur supports-[backdrop-filter]:bg-background/80">
                        <div className="mx-auto flex min-h-16 w-full max-w-6xl items-center gap-4 px-4 py-2 sm:px-6 sm:py-0">
                            {headerContent ? (
                                <div className="flex min-w-0 flex-1 items-center gap-2">
                                    {headerContent}
                                </div>
                            ) : (
                                <Link
                                    href={laneUrl}
                                    className="flex min-w-0 items-center gap-2"
                                    aria-label="Receiving upload page"
                                >
                                    <AppLogo />
                                    {uploadType && (
                                        <span className="hidden truncate border-l pl-3 text-muted-foreground text-sm sm:block">
                                            {uploadType.name}
                                        </span>
                                    )}
                                </Link>
                            )}

                            <div className="ml-auto flex items-center gap-2">
                                {uploadType && !isUnavailablePage && (
                                    <Button asChild variant="outline" size="sm" className="h-10">
                                        <Link href={isHistory ? laneUrl : `${laneUrl}/uploads`}>
                                            {isHistory ? <UploadCloud /> : <Files />}
                                            <span className="hidden sm:inline">
                                                {isHistory ? 'Upload files' : 'My uploads'}
                                            </span>
                                            <span className="sm:hidden">
                                                {isHistory ? 'Upload' : 'Uploads'}
                                            </span>
                                        </Link>
                                    </Button>
                                )}

                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <Button
                                            variant="ghost"
                                            className="size-10 rounded-full p-1"
                                            aria-label="Open account menu"
                                        >
                                            <Avatar className="size-8 overflow-hidden rounded-full">
                                                <AvatarImage
                                                    src={page.props.auth.user.avatar}
                                                    alt={page.props.auth.user.name}
                                                />
                                                <AvatarFallback className="rounded-full bg-primary/10 text-primary">
                                                    {getInitials(page.props.auth.user.name)}
                                                </AvatarFallback>
                                            </Avatar>
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent className="w-64" align="end">
                                        <UserMenuContent user={page.props.auth.user} />
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </div>
                        </div>
                    </header>
                )}

                <div className="flex-1 overflow-y-auto overflow-x-hidden">
                    <div className="flex min-h-full flex-col">{children}</div>
                </div>
                <SessionExpiredDialog />
            </div>
        </ReceivingLayoutContext.Provider>
    );
}
