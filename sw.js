'use strict';

const CACHE_NAME = 'el-radar-shell-v12';

/* INSTALACIÓN */
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll([
                './'
            ]).catch(error => {
                console.warn('No se pudieron almacenar todos los recursos:', error);
            });
        })
    );

    self.skipWaiting();
});

/* ACTIVACIÓN */
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.map(key => {
                    if (key.startsWith('el-radar-') && key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

/* NAVEGACIÓN Y MODO SIN CONEXIÓN */
self.addEventListener('fetch', event => {

    if (event.request.method !== 'GET') return;

    if (event.request.mode !== 'navigate') return;

    event.respondWith(
        fetch(event.request)
            .then(response => {
                if (response && response.ok) {
                    const copy = response.clone();

                    caches.open(CACHE_NAME).then(cache => {
                        cache.put('./', copy);
                    });
                }

                return response;
            })
            .catch(async () => {
                const cached = await caches.match('./');

                if (cached) return cached;

                return new Response(
                    '<!DOCTYPE html><html lang="es"><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>El Radar</title><body><h2>El Radar</h2><p>Sin conexión. Comprueba tu conexión a Internet e inténtalo nuevamente.</p></body></html>',
                    {
                        headers: {
                            'Content-Type': 'text/html; charset=UTF-8'
                        }
                    }
                );
            })
    );
});

/* NOTIFICACIONES PUSH */
self.addEventListener('push', event => {

    event.waitUntil((async () => {

        let data = {};

        try {
            data = event.data ? event.data.json() : {};
        } catch (error) {
            data = {
                title: 'El Radar',
                body: event.data ? event.data.text() : 'Tienes una nueva notificación.'
            };
        }

        const title = data.title || 'El Radar';

        const icon = new URL(
            'icon-192.png',
            self.registration.scope
        ).href;

        const options = {

            body: data.body || 'Tienes una nueva notificación.',

            icon: data.icon || icon,

            badge: data.badge || icon,

            tag: data.tag || 'el-radar-notification',

            renotify: true,

            requireInteraction: true,

            actions: data.actions || [],

            data: {
                path: data.path || data.url || './',
                url: data.url || data.path || './',
                action_url: data.action_url || null,
                notification_id: data.notification_id || null,
                reference_type: data.reference_type || null,
                reference_id: data.reference_id || null
            }
        };

        await self.registration.showNotification(title, options);

    })());

});

/* CLIC EN NOTIFICACIONES */
self.addEventListener('notificationclick', event => {

    event.notification.close();

    event.waitUntil((async () => {

        const data = event.notification.data || {};

        let destination = data.path || data.url || './';

        if (event.action === 'respond' && data.action_url) {
            destination = data.action_url;
        }

        if (
            event.action === 'accept' ||
            event.action === 'reject'
        ) {
            const url = new URL(
                data.action_url || destination,
                self.registration.scope
            );

            url.searchParams.set(
                'decision',
                event.action === 'accept' ? 'aceptar' : 'rechazar'
            );

            destination = url.href;
        }

        let target = new URL(
            destination,
            self.registration.scope
        );

        // Seguridad: impedir navegación a dominios externos.
        if (target.origin !== self.location.origin) {
            target = new URL('./', self.registration.scope);
        }

        const windows = await clients.matchAll({
            type: 'window',
            includeUncontrolled: true
        });

        for (const client of windows) {

            if ('focus' in client) {
                await client.navigate(target.href);
                return client.focus();
            }

        }

        return clients.openWindow(target.href);

    })());

});

/* SINCRONIZACIÓN EN SEGUNDO PLANO: consulta el endpoint real de sesión de El Radar. */
async function syncElRadarSession() {

    const endpoint = new URL('index.php?action=sync', self.registration.scope);

    const response = await fetch(endpoint.href, {

        method: 'POST',

        credentials: 'include',

        cache: 'no-store',

        headers: {
            'Content-Type': 'application/json'
        },

        body: JSON.stringify({
            source: 'background-sync'
        })

    });

    if (!response.ok) {
        throw new Error('Error al sincronizar con el servidor.');
    }

    const result = await response.json();

    if (!result || result.ok !== true) {
        throw new Error('El servidor no confirmó la sincronización.');
    }

    return result;
}

self.addEventListener('sync', event => {

    if (event.tag === 'database-sync') {

        event.waitUntil(
            syncElRadarSession()
        );

    }

});

/* RENOVACIÓN DE SUSCRIPCIÓN PUSH */
self.addEventListener('pushsubscriptionchange', event => {

    event.waitUntil((async () => {

        if (!self.registration.pushManager) return;

        // La renovación automática requiere una clave VAPID pública.
        const publicKey = 'BBD-v6lARrtb_CAuyzoYzdaAZT9Bvrd-JcRkxqSYohBIJ2Rafn5xpwWzvX-5-NOJdDjB0meE02kdRmRsiPT0Myg';

        const normalizedKey = publicKey.replace(/-/g, '+').replace(/_/g, '/');
        const paddedKey = normalizedKey + '='.repeat((4 - normalizedKey.length % 4) % 4);
        const applicationServerKey = Uint8Array.from(
            atob(paddedKey),
            character => character.charCodeAt(0)
        );

        const subscription = await self.registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey
        });

        await fetch('index.php?action=push_subscribe', {

            method: 'POST',

            credentials: 'include',

            headers: {
                'Content-Type': 'application/json'
            },

            body: JSON.stringify({ subscription: subscription.toJSON() })

        });

    })());

});
