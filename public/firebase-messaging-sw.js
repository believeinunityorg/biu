// @version e95f384c7720bb16
// firebase-messaging-sw.js - Single service worker at site root
// Do NOT cache "/" or any HTML/auth routes to prevent 419 CSRF issues.
importScripts("https://www.gstatic.com/firebasejs/10.7.0/firebase-app-compat.js");
importScripts("https://www.gstatic.com/firebasejs/10.7.0/firebase-messaging-compat.js");

const firebaseConfig = {
    apiKey: "AIzaSyD2t6nAHQzhfWWso5nqV0mClX7R-Q6HjlA",
    authDomain: "believe-in-unity-d8adc.firebaseapp.com",
    projectId: "believe-in-unity-d8adc",
    storageBucket: "believe-in-unity-d8adc.firebasestorage.app",
    messagingSenderId: "829146180648",
    appId: "1:829146180648:web:8ba15be8d65c2c54d5991d",
};

firebase.initializeApp(firebaseConfig);
const messaging = firebase.messaging();

const UNITY_MEET_INVITATION_TYPE = "unity_meet_invitation";
const INCOMING_CALL_TYPE = "incoming_call";
const PENDING_CALL_DB = "unity-call";
const PENDING_CALL_STORE = "pending";
const PENDING_CALL_KEY = "incoming";
const PENDING_CALL_TTL_MS = 120000;

function openPendingCallDb() {
    return new Promise(function (resolve, reject) {
        const request = indexedDB.open(PENDING_CALL_DB, 1);
        request.onerror = function () {
            reject(request.error);
        };
        request.onupgradeneeded = function () {
            request.result.createObjectStore(PENDING_CALL_STORE);
        };
        request.onsuccess = function () {
            resolve(request.result);
        };
    });
}

function storePendingCallInDb(data) {
    return openPendingCallDb()
        .then(function (db) {
            return new Promise(function (resolve, reject) {
                const tx = db.transaction(PENDING_CALL_STORE, "readwrite");
                const store = tx.objectStore(PENDING_CALL_STORE);
                store.put({ data: data, storedAt: Date.now() }, PENDING_CALL_KEY);
                tx.oncomplete = function () {
                    resolve();
                };
                tx.onerror = function () {
                    reject(tx.error);
                };
            });
        })
        .catch(function () {});
}

function appNotificationIconUrl() {
    return new URL("/favicon-96x96.png", self.location.origin).href;
}

function resolveOrganizationLogoUrl(data) {
    const orgLogo =
        data && data.organization_logo_url
            ? String(data.organization_logo_url).trim()
            : "";
    if (!orgLogo) {
        return null;
    }
    try {
        return new URL(orgLogo, self.location.origin).href;
    } catch (e) {
        return null;
    }
}

function isAndroidNotificationPlatform() {
    return /android/i.test(self.navigator.userAgent);
}

function isOrganizationCampaignNotification(data) {
    if (!data) {
        return false;
    }

    const campaignId = data.campaign_id ? String(data.campaign_id).trim() : "";
    const moduleName = data.module_name ? String(data.module_name).trim() : "";
    const sourceType = data.source_type ? String(data.source_type).trim() : "";
    const context = data.notification_context ? String(data.notification_context).trim() : "";

    return (
        campaignId !== "" ||
        moduleName === "campaigns" ||
        sourceType === "campaign" ||
        context === "organization_daily_campaign"
    );
}

function isSystemAutomaticNotification(data) {
    if (isOrganizationCampaignNotification(data)) {
        return false;
    }

    const moduleName = data && data.module_name ? String(data.module_name).trim() : "";
    const sourceType = data && data.source_type ? String(data.source_type).trim() : "";
    const type = data && data.type ? String(data.type).trim() : "";

    return (
        moduleName === "daily_engagement" ||
        sourceType === "daily_engagement" ||
        type === "daily_engagement"
    );
}

/** PWA app icon on the left (Android); org logo via `icon` shows as the right-side dynamic icon. */
function resolveNotificationDisplayIcon(data) {
    if (data && data.type === INCOMING_CALL_TYPE) {
        const callerAvatar = data.caller_avatar ? String(data.caller_avatar).trim() : "";
        if (callerAvatar) {
            return callerAvatar;
        }
    }

    if (isSystemAutomaticNotification(data)) {
        return appNotificationIconUrl();
    }

    const orgLogo = resolveOrganizationLogoUrl(data);
    if (orgLogo) {
        return orgLogo;
    }

    return appNotificationIconUrl();
}

function resolveNotificationImageUrl(data) {
    // Do not use `image` for org logos — Android renders it as a large hero at the bottom.
    if (data && data.type === INCOMING_CALL_TYPE) {
        const callerAvatar = data.caller_avatar ? String(data.caller_avatar).trim() : "";
        return callerAvatar || null;
    }

    return null;
}

function resolveNotificationBadgeUrl() {
    return appNotificationIconUrl();
}

function trackPushOpenFromData(data) {
    const logId = data.notification_log_id;
    const recipientId = data.recipient_id;
    if (!logId || !recipientId) {
        return;
    }
    fetch("/api/push-notifications/open", {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
        },
        credentials: "same-origin",
        body: JSON.stringify({
            notification_log_id: Number(logId),
            recipient_id: Number(recipientId),
        }),
    }).catch(function () {});
}

function resolveClickUrl(data) {
    return data.join_url || data.click_action || data.url || "/";
}

function buildNotificationOptions(title, body, data) {
    const clickUrl = resolveClickUrl(data);
    const tag = (data.type || "push") + ":" + (data.call_id || data.livestream_id || data.source_id || title);
    const icon = resolveNotificationDisplayIcon(data);
    const badge = resolveNotificationBadgeUrl();
    const image = resolveNotificationImageUrl(data);
    const options = {
        body: body || undefined,
        icon: icon,
        badge: badge,
        tag: tag,
        data: Object.assign({}, data, {
            click_action: clickUrl,
            url: clickUrl,
            join_url: data.join_url || clickUrl,
        }),
    };

    if (image) {
        options.image = image;
    }

    if (data.type === UNITY_MEET_INVITATION_TYPE) {
        options.actions = [{ action: "join", title: "Join" }];
    }

    if (data.type === INCOMING_CALL_TYPE) {
        const ringUrl = data.ring_url || data.join_url || clickUrl;
        options.actions = [
            { action: "accept", title: "Accept" },
            { action: "decline", title: "Decline" },
        ];
        options.requireInteraction = true;
        options.renotify = true;
        options.silent = false;
        options.vibrate = [500, 250, 500, 250, 500, 250, 500];
        options.data = Object.assign({}, options.data, {
            ring_url: ringUrl,
            click_action: ringUrl,
            url: ringUrl,
        });
    }

    return options;
}

function resolveIncomingCallRingUrl(data) {
    const ringUrl = data.ring_url || data.join_url || data.click_action || data.url || "/";
    return new URL(ringUrl, self.location.origin).href;
}

function postIncomingCallToClients(clientList, data) {
    clientList.forEach(function (client) {
        client.postMessage({
            type: "unity-call-incoming-push",
            data: data,
        });
    });
}

function focusAndShowIncomingCall(client, data) {
    postIncomingCallToClients([client], data);
    if (!("focus" in client)) {
        return Promise.resolve(false);
    }
    return client.focus().then(function () {
        return true;
    });
}

function openFreshIncomingCallWindow() {
    if (!self.clients.openWindow) {
        return Promise.resolve(false);
    }

    const chatUrl = new URL("/chat", self.location.origin).href;
    return self.clients.openWindow(chatUrl).then(function (windowClient) {
        return Boolean(windowClient);
    }).catch(function () {
        return false;
    });
}

/** Bring the in-app incoming call screen to the foreground without a notification tap. */
function notifyOpenClientsIncomingCall(data) {
    const absoluteRingUrl = resolveIncomingCallRingUrl(data);

    return storePendingCallInDb(data).then(function () {
        return self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (clientList) {
            postIncomingCallToClients(clientList, data);

            if (clientList.length === 0) {
                return openFreshIncomingCallWindow().then(function (opened) {
                    return { opened: opened, absoluteRingUrl: absoluteRingUrl };
                });
            }

            return focusAndShowIncomingCall(clientList[0], data).then(function (focused) {
                if (focused) {
                    return { opened: true, absoluteRingUrl: absoluteRingUrl };
                }

                return openFreshIncomingCallWindow().then(function (opened) {
                    return { opened: opened, absoluteRingUrl: absoluteRingUrl };
                });
            });
        });
    });
}

/** Native OS notification (Windows/macOS/Android) when the app is in the background. */
messaging.onBackgroundMessage((payload) => {
    const data = payload.data || {};
    const isIncomingCall = data.type === INCOMING_CALL_TYPE;
    const isGroupCall = data.is_group_call === "1" || data.is_group_call === "true";
    const title = isIncomingCall && isGroupCall && data.chat_room_name
        ? data.chat_room_name
        : payload.notification?.title || data.title || "Believe In Unity";
    const body = isIncomingCall && isGroupCall && data.caller_name
        ? data.caller_name + " is calling"
        : payload.notification?.body || data.body || data.message || "";

    if (data.type === INCOMING_CALL_TYPE) {
        return notifyOpenClientsIncomingCall(data).then(function () {
            return self.registration.showNotification(
                title,
                buildNotificationOptions(title, body, data),
            );
        });
    }

    return self.registration.showNotification(
        title,
        buildNotificationOptions(title, body, data),
    );
});

// Cache version bump for post-deploy cleanup (invalidates old caches)
const CACHE_NAME = "pwa-cache-e95f384c7720bb16";
// Only cache static assets; do NOT cache "/" or HTML/auth routes
const urlsToCache = ["/offline.html", "/manifest.json"];

const noCachePaths = ["/", "/login", "/register", "/wallet", "/api/", "/sanctum/", "/unity-call", "/unity-calls"];
function shouldBypassCache(url) {
    const path = new URL(url).pathname;
    return noCachePaths.some((p) => path === p || path.startsWith(p));
}

function isFirebaseOrGoogleUrl(url) {
    return (
        url.includes("googleapis.com") ||
        url.includes("gstatic.com") ||
        url.includes("google.com") ||
        url.includes("firebaseio.com")
    );
}

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(urlsToCache))
    );
    // Wait for the app to send SKIP_WAITING before activating (PWAUpdatePrompt).
});

self.addEventListener("message", (event) => {
    if (event.data && event.data.type === "SKIP_WAITING") {
        self.skipWaiting();
    }
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => caches.delete(cacheName))
            ).then(() => caches.open(CACHE_NAME).then((cache) => cache.addAll(urlsToCache)));
        })
    );
    self.clients.claim();
});

// Navigation and auth routes: network only (no cache) to prevent stale CSRF → 419
// Do NOT intercept API/data routes: let the browser handle them natively to avoid
// "Failed to fetch" / net::ERR_FAILED (e.g. /wallet/balance, Inertia XHR, unity-videos).
self.addEventListener("fetch", (event) => {
    const url = event.request.url;

    // Never intercept Firebase / Google push infrastructure (breaks FCM token + delivery)
    if (isFirebaseOrGoogleUrl(url)) {
        return;
    }

    const path = new URL(url).pathname;
    const isNavigate = event.request.mode === "navigate";

    // Never proxy call pages/API through the SW — avoids FetchEvent network errors on auth/Inertia visits.
    if (path.startsWith("/unity-call") || path.startsWith("/unity-calls")) {
        return;
    }

    // Let browser handle same-origin API/data requests without SW (avoids fetch failures)
    if (!isNavigate) {
        if (path.startsWith("/api/") || path.startsWith("/wallet/") || path.startsWith("/sanctum/") ||
            path.startsWith("/unity-videos/") || path.startsWith("/unity-call") || path.startsWith("/unity-calls/") ||
            path.startsWith("/broadcasting/") || path === "/" || path.startsWith("/login") || path.startsWith("/register")) {
            return; // do not call respondWith — browser handles request natively
        }
    }

    if (isNavigate || shouldBypassCache(url)) {
        event.respondWith(fetch(event.request));
        return;
    }
    // Static assets: optional cache (e.g. manifest, offline page)
    if (event.request.url.includes("/manifest.json") || event.request.url.includes("/offline.html")) {
        event.respondWith(
            caches.match(event.request).then((cached) => cached || fetch(event.request))
        );
        return;
    }
    // All other requests: network first (no caching of HTML/API)
    event.respondWith(fetch(event.request));
});

function openNotificationUrl(clientList, absoluteUrl) {
    for (const client of clientList) {
        if ("focus" in client) {
            if ("navigate" in client) {
                return client.focus().then(() => client.navigate(absoluteUrl));
            }
            return client.focus();
        }
    }
    if (self.clients.openWindow) {
        return self.clients.openWindow(absoluteUrl);
    }
}

self.addEventListener("notificationclick", (event) => {
    event.notification.close();
    const data = event.notification.data || {};
    trackPushOpenFromData(data);
    let urlToOpen = resolveClickUrl(data);

    if (event.action === "join") {
        urlToOpen = data.join_url || data.click_action || data.url || "/";
    }

    if (event.action === "accept") {
        const acceptUrl = data.accept_url;
        const joinUrl = data.join_url || data.click_action || data.url || "/";
        if (acceptUrl) {
            event.waitUntil(
                fetch(acceptUrl, {
                    method: "GET",
                    credentials: "include",
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                })
                    .catch(function () {})
                    .then(function () {
                        return self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (clientList) {
                            return openNotificationUrl(clientList, new URL(joinUrl, self.location.origin).href);
                        });
                    }),
            );
            return;
        }
        urlToOpen = joinUrl;
    }

    if (event.action === "decline") {
        const declineUrl = data.decline_url;
        if (declineUrl) {
            event.waitUntil(
                fetch(declineUrl, {
                    method: "GET",
                    credentials: "include",
                    headers: {
                        Accept: "application/json",
                        "X-Requested-With": "XMLHttpRequest",
                    },
                }).catch(function () {}),
            );
        }
        return;
    }

    if (
        (!event.action || event.action === "") &&
        data.type === INCOMING_CALL_TYPE
    ) {
        const ringUrl = data.ring_url || data.join_url || data.click_action || data.url || "/";
        const absoluteRingUrl = new URL(ringUrl, self.location.origin).href;
        event.waitUntil(
            self.clients
                .matchAll({ type: "window", includeUncontrolled: true })
                .then((clientList) => openNotificationUrl(clientList, absoluteRingUrl)),
        );
        return;
    }

    const absoluteUrl = new URL(urlToOpen, self.location.origin).href;

    event.waitUntil(
        self.clients
            .matchAll({ type: "window", includeUncontrolled: true })
            .then((clientList) => openNotificationUrl(clientList, absoluteUrl)),
    );
});
