// Push notifications: registers the service worker, subscribes this browser and saves the subscription.

const decodeKey = (value) => {
    const base64 = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=');

    return Uint8Array.from(window.atob(base64), (character) => character.charCodeAt(0));
};

const describeDevice = () => {
    const agent = navigator.userAgent;
    const device = /iphone/i.test(agent) ? 'iPhone' : /ipad/i.test(agent) ? 'iPad' : /android/i.test(agent) ? 'Android' : /mac os/i.test(agent) ? 'Mac' : /windows/i.test(agent) ? 'Windows' : 'Computer';
    const browser = /edg\//i.test(agent) ? 'Edge' : /firefox/i.test(agent) ? 'Firefox' : /chrome|crios/i.test(agent) ? 'Chrome' : /safari/i.test(agent) ? 'Safari' : 'Browser';

    return `${device} · ${browser}`;
};

document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-push-enable]') : null;
    const panel = button?.closest('[data-push-devices]');
    if (!button || !panel) return;
    const message = panel.querySelector('[data-push-message]');
    const fail = (text) => {
        if (message) {
            message.textContent = text;
            message.hidden = false;
        }
    };
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        fail('This browser can’t get push notifications. On iPhone and iPad, add BuildPusher to your Home Screen first.');

        return;
    }
    button.disabled = true;
    try {
        if ((await Notification.requestPermission()) !== 'granted') {
            fail('Notifications are blocked for this site. Allow them in the browser’s settings, then try again.');

            return;
        }
        const registration = await navigator.serviceWorker.register(panel.dataset.pushWorker, { scope: '/' });
        await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: decodeKey(panel.dataset.pushKey) });
        const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
        const response = await fetch(panel.dataset.pushUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': token },
            body: JSON.stringify({ ...subscription.toJSON(), device: describeDevice() }),
            credentials: 'same-origin',
        });
        if (!response.ok) {
            fail('BuildPusher couldn’t save this device. Try again.');

            return;
        }
        window.location.reload();
    } catch (error) {
        fail('Push notifications couldn’t be turned on here.');
    } finally {
        button.disabled = false;
    }
});
