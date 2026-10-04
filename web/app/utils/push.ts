// Push notifications: register the service worker (public/sw.js), subscribe this browser and save the subscription.

/** Decode the server's base64url VAPID key into the bytes the browser wants. */
function decodeKey(value: string): Uint8Array<ArrayBuffer> {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=');
    return Uint8Array.from(window.atob(base64), (character) => character.charCodeAt(0));
}

/** Name this device the way the settings list shows it, such as "Mac · Safari". */
function describeDevice(): string {
    const agent = navigator.userAgent;
    const device = /iphone/i.test(agent) ? 'iPhone' : /ipad/i.test(agent) ? 'iPad' : /android/i.test(agent) ? 'Android' : /mac os/i.test(agent) ? 'Mac' : /windows/i.test(agent) ? 'Windows' : 'Computer';
    const browser = /edg\//i.test(agent) ? 'Edge' : /firefox/i.test(agent) ? 'Firefox' : /chrome|crios/i.test(agent) ? 'Chrome' : /safari/i.test(agent) ? 'Safari' : 'Browser';
    return `${device} · ${browser}`;
}

/** Why push couldn't be turned on, for the person to read. */
export type PushProblem = 'unsupported' | 'blocked' | 'failed';

/** Turn on push notifications for this browser; returns the problem if it couldn't. */
export async function enablePush(publicKey: string): Promise<PushProblem | null> {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        return 'unsupported';
    }
    try {
        if ((await Notification.requestPermission()) !== 'granted') {
            return 'blocked';
        }
        const registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
        await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: decodeKey(publicKey) });
        await send('POST', '/settings/push-devices', { ...subscription.toJSON(), device: describeDevice() });
        return null;
    } catch {
        return 'failed';
    }
}
