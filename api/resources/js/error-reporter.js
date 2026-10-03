// Reports the platform's own JavaScript errors into its Monitoring, so broken pages show up like server errors do.
// Only errors from this site's own scripts are sent (not browser extensions), at most five per page view.
const endpoint = document.querySelector('meta[name="error-endpoint"]')?.content;
const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
let sent = 0;

const send = (error) => {
  if (!endpoint || !csrf || sent >= 5) return;
  sent += 1;
  fetch(endpoint, {
    method: 'POST',
    keepalive: true,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ ...error, page: location.href.split('#')[0] }),
  }).catch(() => {});
};

window.addEventListener('error', (event) => {
  if (!event.filename || !event.filename.startsWith(location.origin)) return;
  send({
    message: String(event.message || 'Script error').slice(0, 1000),
    source: event.filename.slice(0, 2048),
    line: event.lineno || null,
    column: event.colno || null,
    stack: event.error?.stack ? String(event.error.stack).slice(0, 8000) : null,
  });
});

window.addEventListener('unhandledrejection', (event) => {
  const reason = event.reason;
  const stack = reason?.stack ? String(reason.stack) : '';
  // Skip rejections whose stack points only at other origins (extensions, third-party widgets).
  if (stack && !stack.includes(location.origin)) return;
  send({
    message: `Unhandled promise rejection: ${String(reason?.message ?? reason).slice(0, 950)}`,
    stack: stack ? stack.slice(0, 8000) : null,
  });
});
