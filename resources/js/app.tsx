import '../css/app.css';
import './lib/polyfills';
import './bootstrap'; // sets window.axios — must run before timezone init
import './lib/timezone-detection'; // X-Timezone on axios + fetch for every page

import type { GlobalEvent } from '@inertiajs/core';
import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { configureEcho } from '@laravel/echo-react';
import { buildReverbEchoConfig, refreshEchoAuthHeaders, syncEchoCsrfToken } from './lib/reverb-config';
import { createRoot } from 'react-dom/client';
import { NotificationProvider } from './components/frontend/notification-provider';
import { PwaInstallPrompt } from './components/pwa/pwa-install-prompt';
import { initializeTheme } from './hooks/use-appearance';
import { registerServiceWorker } from './pwa/register-service-worker';
import { PWAUpdatePrompt } from './components/PWAUpdatePrompt';
import { isLivestockDomain } from './lib/livestock-domain';
import { isMerchantDomain } from './lib/merchant-domain';
import { applyFirebaseWebConfig, ensureMessagingReady, resetMessagingRegistration } from './lib/firebase';
import { showNativePushNotification } from './lib/firebase-push-toast';
import { syncPushTokenWithServer, startPushTokenRefreshListeners } from './lib/push-token-sync';
import { ensureMobilePwaCookie, shouldAutoPromptForPushPermission } from './lib/push-environment';
import IncomingCallOverlay from './components/call/IncomingCallOverlay';
import CallPermissionsPrompt from './components/call/CallPermissionsPrompt';
import LocationPermissionPrompt from './components/LocationPermissionPrompt';
import UnityCallGlobalListener from './components/call/UnityCallGlobalListener';
import { UnityCallSessionProvider } from './contexts/unity-call-session-context';
import { setupSwIncomingCallBridge } from './lib/swIncomingCallBridge';
import { Toaster } from 'react-hot-toast';
import { getBrowserTimezone } from './lib/timezone-detection';
import { getCsrfHeaders, getCsrfToken, syncCsrfMetaFromCookie } from './lib/csrf';
import { initStoredAppVersion, markPwaUpdateComplete, fetchServerAppVersion } from './lib/pwa-update';
import axios from 'axios';

configureEcho(buildReverbEchoConfig());

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Determine progress bar color based on domain
const getProgressColor = () => {
    if (typeof window !== 'undefined') {
        if (isMerchantDomain()) {
            return '#2563EB'; // BIU Merchant Hub accent
        }
        if (isLivestockDomain()) {
            return '#f59e0b'; // Amber-600 to match livestock theme
        }
    }
    return '#7F03DB'; // Purple for main app
};

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        // Initial sync: set meta from first page load so CSRF is never stale (e.g. public view, PWA).
        const initialToken = (props as { initialPage?: { props?: { csrf_token?: string } } })?.initialPage?.props?.csrf_token;
        if (initialToken && typeof document !== 'undefined') {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) meta.setAttribute('content', initialToken);
            else {
                const newMeta = document.createElement('meta');
                newMeta.name = 'csrf-token';
                newMeta.content = initialToken;
                document.head.appendChild(newMeta);
            }
            syncEchoCsrfToken(initialToken);
            refreshEchoAuthHeaders();
        }

        ensureMobilePwaCookie();

        type InitialProps = {
            initialPage?: {
                props?: {
                    csrf_token?: string;
                    auth?: { user?: { id?: number } };
                    firebaseWeb?: Parameters<typeof applyFirebaseWebConfig>[0];
                };
            };
        };
        const initial = props as InitialProps;
        applyFirebaseWebConfig(initial.initialPage?.props?.firebaseWeb);

        // After every Inertia navigation (including post-login redirect), sync meta so next POST doesn't get 419.
        router.on('success', (event: GlobalEvent<'success'>) => {
            const raw = event.detail.page?.props?.csrf_token;
            const token = typeof raw === 'string' ? raw : '';
            if (token && typeof document !== 'undefined') {
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) meta.setAttribute('content', token);
                else {
                    const newMeta = document.createElement('meta');
                    newMeta.name = 'csrf-token';
                    newMeta.content = token;
                    document.head.appendChild(newMeta);
                }
                syncEchoCsrfToken(token);
            }
            refreshEchoAuthHeaders();

            const pageProps = event.detail.page?.props as {
                auth?: { user?: { id?: number } };
                pwaVersion?: string;
            };

            const pwaVersion = pageProps?.pwaVersion;
            if (typeof pwaVersion === 'string' && pwaVersion && typeof document !== 'undefined') {
                const versionMeta = document.querySelector('meta[name="app-version"]');
                if (versionMeta) {
                    versionMeta.setAttribute('content', pwaVersion);
                }
            }

            const userId = pageProps?.auth?.user?.id;
            // Keep user-id meta in sync across Inertia visits (login/logout without full reload).
            if (typeof document !== 'undefined') {
                let userMeta = document.querySelector('meta[name="user-id"]');
                if (userId) {
                    if (!userMeta) {
                        userMeta = document.createElement('meta');
                        userMeta.setAttribute('name', 'user-id');
                        document.head.appendChild(userMeta);
                    }
                    userMeta.setAttribute('content', String(userId));
                } else if (userMeta) {
                    userMeta.remove();
                }
            }

            if (userId && !isLivestockDomain()) {
                void registerServiceWorker()?.then(async () => {
                    await syncPushTokenWithServer({ prompt: shouldAutoPromptForPushPermission() });
                }).catch((err) => {
                    console.warn('[App] Push token sync after navigation failed:', err);
                });
            }
        });

        const initialUserId = initial.initialPage?.props?.auth?.user?.id;

        root.render(
          <NotificationProvider>
            <UnityCallSessionProvider>
              <App {...props} />
              <IncomingCallOverlay authUserId={initialUserId ?? null} />
              <CallPermissionsPrompt authUserId={initialUserId ?? null} />
              <LocationPermissionPrompt />
              <UnityCallGlobalListener authUserId={initialUserId ?? null} />
              <Toaster
                position="top-right"
                gutter={8}
                toastOptions={{
                  duration: 4000,
                  style: {
                    background: 'hsl(var(--background))',
                    color: 'hsl(var(--foreground))',
                    border: '1px solid hsl(var(--border))',
                  },
                  success: {
                    duration: 4000,
                    style: {
                      background: '#10b981',
                      color: '#ffffff',
                      border: '1px solid #059669',
                    },
                    iconTheme: {
                      primary: '#ffffff',
                      secondary: '#10b981',
                    },
                  },
                  error: {
                    duration: 5000,
                    iconTheme: {
                      primary: '#ef4444',
                      secondary: '#fff',
                    },
                  },
                }}
              />
              <PwaInstallPrompt />
              <PWAUpdatePrompt />
            </UnityCallSessionProvider>
          </NotificationProvider>
        );

        if (!isLivestockDomain()) {
            initStoredAppVersion();
            setupSwIncomingCallBridge();
            void fetchServerAppVersion().then((server) => {
                if (server?.version) {
                    const stored = localStorage.getItem('biu_pwa_version');
                    if (!stored || stored === server.version) {
                        markPwaUpdateComplete(server.version);
                    }
                }
            });
            void registerServiceWorker()?.then(async () => {
                if (initialUserId) {
                    await syncPushTokenWithServer({ prompt: shouldAutoPromptForPushPermission() });
                }
            }).catch((err) => {
                console.warn('[App] Initial push token sync failed:', err);
            });
            if (initialUserId) {
                startPushTokenRefreshListeners(() => {
                    const metaUserId = document.querySelector('meta[name="user-id"]')?.getAttribute('content');
                    return Boolean(metaUserId);
                });
                void ensureMessagingReady();
            }
        }
    },
    progress: {
        color: getProgressColor(),
        showSpinner: true,
    },
});

// Every Inertia visit (GET/POST/Link) must send timezone + CSRF so Laravel accepts the request.
if (typeof window !== 'undefined') {
    router.on('before', (event: GlobalEvent<'before'>) => {
        event.detail.visit.headers['X-Timezone'] = getBrowserTimezone();
        const csrf = getCsrfToken();
        if (csrf) {
            event.detail.visit.headers['X-CSRF-TOKEN'] = csrf;
        }
    });
}

// Global axios: CSRF from meta (CsrfTokenSync + router.on('success')), cookie fallback when meta is empty.
if (typeof window !== 'undefined') {
    axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
    axios.defaults.withCredentials = true;
    axios.interceptors.request.use((config) => {
        const headers = getCsrfHeaders();
        for (const [key, value] of Object.entries(headers)) {
            config.headers[key] = value;
        }
        config.headers['X-Timezone'] = getBrowserTimezone();
        return config;
    });
    axios.interceptors.response.use(
        (r) => r,
        (err) => {
            if (err.response?.status === 419) {
                syncCsrfMetaFromCookie();
            }
            if (err.response?.status === 401 && document.querySelector('meta[name="user-id"]')?.getAttribute('content')) {
                window.location.href = '/login';
            }
            return Promise.reject(err);
        }
    );
}

// Add domain class to body for conditional styling
if (typeof window !== 'undefined') {
    if (isMerchantDomain()) {
        document.body.classList.add('merchant-domain');
    } else if (isLivestockDomain()) {
        document.body.classList.add('livestock-domain');
    } else {
        document.body.classList.add('main-domain');
    }
}

// Merchant Hub: default to dark (navy) UI unless explicitly overridden.
if (typeof window !== 'undefined' && isMerchantDomain()) {
    try {
        const existing = localStorage.getItem('appearance');
        if (!existing) {
            localStorage.setItem('appearance', 'dark');
            document.cookie = `appearance=dark;path=/;max-age=${365 * 24 * 60 * 60};SameSite=Lax`;
        }
    } catch {
        // ignore
    }
}

// This will set light / dark mode on load...
initializeTheme();

// Push → native OS notifications (service worker + Notification API). Requires permission granted.
if (typeof window !== 'undefined' && !isLivestockDomain()) {
    if (import.meta.env.DEV) {
        (window as Window & { enableBelievePush?: () => Promise<string | null> }).enableBelievePush = () =>
            syncPushTokenWithServer({ prompt: true });
        (window as Window & { testBelievePushToast?: () => void }).testBelievePushToast = () =>
            void showNativePushNotification({
                title: 'Unity Meet invitation',
                body: 'Test host invited you to a Unity Meet.',
                data: {
                    type: 'unity_meet_invitation',
                    join_url: '/livestreams/join/test-room',
                    click_action: '/livestreams/join/test-room',
                },
            });
    }

    if ('permissions' in navigator) {
        void navigator.permissions.query({ name: 'notifications' as PermissionName }).then((status) => {
            status.addEventListener('change', () => {
                if (Notification.permission === 'granted') {
                    resetMessagingRegistration();
                    void ensureMessagingReady();
                }
            });
        });
    }
}

// Re-register FCM after SW updates (new firebase-messaging-sw.js deploy)
if (typeof window !== 'undefined' && !isLivestockDomain() && 'serviceWorker' in navigator) {
    navigator.serviceWorker.addEventListener('controllerchange', async () => {
        try {
            await new Promise((r) => setTimeout(r, 1000));
            resetMessagingRegistration();
            const userId = document.querySelector('meta[name="user-id"]')?.getAttribute('content');
            if (userId && Notification.permission === 'granted') {
                await syncPushTokenWithServer({ force: true });
            }
        } catch (e) {
            console.error('[App] Push re-init after controller change:', e);
        }
    });
}
