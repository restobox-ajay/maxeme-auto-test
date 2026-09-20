#!/bin/bash
set -euo pipefail

# Only in the remote sandbox (Claude Code on the web) -- never touch a
# teammate's local machine running Claude Code against this same repo.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

DENO_INSTALL="$HOME/.deno"

# Idempotent: hooks can run more than once per session (resume, compact),
# and the container itself is cached after a successful run, so this should
# be a near-instant no-op on the common path.
#
# Checked at the known install path, not via `command -v deno` -- this
# script's own PATH was never updated by a previous run (only
# $CLAUDE_ENV_FILE was, which the *next* shell sources, not this process),
# so `command -v` would find nothing and reinstall every single time.
if [ ! -x "$DENO_INSTALL/bin/deno" ]; then
    # deno.land/install.sh is blocked by this sandbox's outbound proxy
    # (CONNECT to deno.land returns 403 -- confirmed, not a fluke: two
    # separate attempts, plus a `cargo install deno` fallback that failed
    # for an unrelated reason, a transitive dependency needing a Rust
    # compiler feature the pinned stable toolchain doesn't have). GitHub's
    # release CDN is reachable through the same proxy, so fetch the
    # prebuilt zip from there instead -- same binary the official
    # installer would have placed at this exact path, just a different
    # transport.
    case "$(uname -m)" in
        x86_64)  deno_target="x86_64-unknown-linux-gnu" ;;
        aarch64) deno_target="aarch64-unknown-linux-gnu" ;;
        *)       echo "session-start.sh: unsupported arch $(uname -m) for Deno, skipping" >&2; exit 0 ;;
    esac

    tmp_zip="$(mktemp -d)/deno.zip"
    if curl -fsSL -o "$tmp_zip" "https://github.com/denoland/deno/releases/latest/download/deno-${deno_target}.zip"; then
        mkdir -p "$DENO_INSTALL/bin"
        unzip -oq "$tmp_zip" -d "$DENO_INSTALL/bin/"
        chmod +x "$DENO_INSTALL/bin/deno"
    else
        echo "session-start.sh: could not download Deno from GitHub releases, skipping" >&2
    fi
    rm -rf "$(dirname "$tmp_zip")"
fi

# Makes `deno` resolve for Claude's own shell. $CLAUDE_ENV_FILE is sourced
# into the session's environment; this script's own PATH is not touched by
# any of the above.
echo "export DENO_INSTALL=\"$DENO_INSTALL\"" >> "$CLAUDE_ENV_FILE"
echo "export PATH=\"$DENO_INSTALL/bin:\$PATH\"" >> "$CLAUDE_ENV_FILE"

# Some containers this repo has been handed have shipped PHP with the
# bcmath extension missing outright -- not disabled, not present on disk at
# all. That is a much bigger problem than it sounds: App\Service\
# QuantityScale::canonical() calls bcadd() directly, and every
# quantity-bearing entity setter (InventoryDetail, InventoryLot,
# SalesOrderLine, ProductReorderRule, ...) routes through it, so its
# absence fails most of modules/InventoryDepthBundle's suite (and plenty
# of core's) with "Call to undefined function App\Service\bcadd()" --
# silently, since nothing in that message names bcmath as the actual cause.
#
# Idempotent, same reasoning as Deno above: checked by whether the module
# is already loaded, not by whether this script has run before.
if ! php -m 2>/dev/null | grep -qi '^bcmath$'; then
    # apt's ondrej/php PPA (what installed this PHP build) is blocked by
    # this sandbox's proxy exactly like deno.land is -- confirmed directly,
    # both via `apt-get install php8.4-bcmath` and a direct curl at the
    # PPA's own .deb. GitHub is reachable through the same proxy (as it is
    # for Deno above), and php-src -- the extension's own upstream source,
    # not a redistribution -- is mirrored there, so build the extension
    # from source instead of fetching a package. phpize/phpconfig ship with
    # this image's PHP already; if they don't, or the toolchain to use them
    # doesn't, this whole block degrades to the same "skip and say why" every
    # other best-effort step here does, rather than failing the session.
    if command -v phpize >/dev/null 2>&1 && command -v make >/dev/null 2>&1 && command -v gcc >/dev/null 2>&1; then
        php_ext_dir="$(php -r 'echo ini_get("extension_dir");' 2>/dev/null || true)"
        php_conf_dir="$(php -r 'echo PHP_CONFIG_FILE_SCAN_DIR;' 2>/dev/null || true)"
        php_tag="php-$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"

        if [ -n "$php_ext_dir" ] && [ -n "$php_conf_dir" ] && [ -n "$php_tag" ] && [ -w "$php_ext_dir" ] && [ -w "$php_conf_dir" ]; then
            bcmath_src="$(mktemp -d)"
            if git clone --quiet --depth 1 --branch "$php_tag" --filter=blob:none --sparse \
                    https://github.com/php/php-src.git "$bcmath_src" 2>/dev/null \
                && git -C "$bcmath_src" sparse-checkout set ext/bcmath 2>/dev/null \
                && ( cd "$bcmath_src/ext/bcmath" \
                     && phpize >/dev/null 2>&1 \
                     && ./configure >/dev/null 2>&1 \
                     && make -j"$(nproc)" >/dev/null 2>&1 ); then
                cp "$bcmath_src/ext/bcmath/modules/bcmath.so" "$php_ext_dir/bcmath.so"
                echo "extension=bcmath.so" > "$php_conf_dir/20-bcmath.ini"
            else
                echo "session-start.sh: could not build bcmath from php-src ($php_tag), skipping" >&2
            fi
            rm -rf "$bcmath_src"
        else
            echo "session-start.sh: PHP extension/conf.d directory not found or not writable, skipping bcmath" >&2
        fi
    else
        echo "session-start.sh: no C toolchain (phpize/make/gcc) available, skipping bcmath" >&2
    fi
fi
