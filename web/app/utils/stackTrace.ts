// Reading the stack traces exceptions arrive with (PHP, JavaScript and Python), so issues can show them frame by frame.

/** One line of a stack trace: the function, where it is, and whether it's the app's own code. */
export type StackFrame = { fn: string; file: string; line: number | null; app: boolean };

// Frames from dependencies, the language runtime or the system, rather than the app's own code.
const LIBRARY = /(^|\/)(vendor|node_modules|site-packages|dist-packages)\/|^(internal|node):|^\/usr\/|<anonymous>|\[internal\]/;

/**
 * Split a stack trace into frames, newest first, or return none when the text isn't one we know how to read.
 *
 * @param text The stack trace as sent.
 */
export function parseStackTrace(text: string | null | undefined): StackFrame[] {
    if (!text) {
        return [];
    }
    const frames: StackFrame[] = [];
    for (const raw of text.split('\n')) {
        const line = raw.trim();
        // PHP: #0 /var/www/app/Http/Controllers/CheckoutController.php(42): App\Services\Cart->total()
        const php = /^#\d+\s+(.+?)\((\d+)\):\s*(.+)$/.exec(line);
        // JavaScript (V8): at total (webpack:///src/cart.ts:42:7), or at /app/server.js:10:3
        const js = /^at\s+(?:(.+?)\s+\()?(.+?):(\d+)(?::\d+)?\)?$/.exec(line);
        // Python: File "/app/cart.py", line 42, in total
        const python = /^File "(.+?)", line (\d+), in (.+)$/.exec(line);
        if (php) {
            frames.push({ fn: php[3]!, file: php[1]!, line: Number(php[2]), app: !LIBRARY.test(php[1]!) });
        } else if (js) {
            frames.push({ fn: js[1] ?? '(anonymous)', file: js[2]!, line: Number(js[3]), app: !LIBRARY.test(js[2]!) });
        } else if (python) {
            frames.push({ fn: python[3]!, file: python[1]!, line: Number(python[2]), app: !LIBRARY.test(python[1]!) });
        }
    }

    return frames;
}
