import { createInertiaApp, router } from '@inertiajs/react';
import { announceSessionExpired } from '@/components/session-expired-dialog';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme, setPageForcesLight } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import DriverLayout from '@/layouts/driver-layout';
import ReceivingLayout from '@/layouts/receiving-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        setPageForcesLight(
            [
                'admin/',
                'auth/',
                'upload/',
                'uploader/',
                'review/',
                'settings/',
                'warehouse/',
                'driver/',
            ].some((prefix) => name.startsWith(prefix)),
        );

        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('review/'):
                return null;
            case name === 'admin/otp':
                return ReceivingLayout;
            case name.startsWith('upload/'):
                return ReceivingLayout;
            case name.startsWith('warehouse/'):
                return ReceivingLayout;
            case name.startsWith('driver/'):
                return DriverLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

router.on('httpException', (event) => {
    const response = event.detail.response;
    if (response?.status === 401 || response?.status === 419) {
        event.preventDefault();
        announceSessionExpired(
            typeof response.data === 'object' &&
                response.data !== null &&
                'message' in response.data
                ? (response.data.message as string)
                : undefined,
        );
    }
});
