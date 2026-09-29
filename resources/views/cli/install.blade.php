{!! '#!/bin/sh' !!}
# Installs the BuildPusher CLI: curl -fsSL {{ route('cli.install') }} | sh
# Set BUILDPUSHER_INSTALL_DIR to choose where it goes (default /usr/local/bin, or ~/.local/bin without write access).
set -eu

command -v php >/dev/null 2>&1 || { echo "The BuildPusher CLI needs PHP 8.1 or later on your PATH." >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || { echo "The BuildPusher CLI needs PHP 8.1 or later; you have $(php -r 'echo PHP_VERSION;')." >&2; exit 1; }

DIR="${BUILDPUSHER_INSTALL_DIR:-/usr/local/bin}"
if [ ! -w "$DIR" ]; then
    DIR="$HOME/.local/bin"
    mkdir -p "$DIR"
fi
TMP=$(mktemp)
curl -fsSL "{{ $source }}" -o "$TMP"
php -l "$TMP" >/dev/null
chmod 755 "$TMP"
mv "$TMP" "$DIR/buildpusher"

echo "Installed $DIR/buildpusher"
case ":$PATH:" in *":$DIR:"*) ;; *) echo "Add $DIR to your PATH to run it from anywhere." ;; esac
echo "Next: buildpusher login"
