#!/bin/bash
#
# Reproducible build of the nut-dw plugin package (archive/nut-plugin-<version>-x86_64-<build>.txz).
#
# Usage: build/build-plugin.sh <version> [build] [ssh-target]
#   e.g. build/build-plugin.sh 2026.10.24
#        build/build-plugin.sh 2026.10.24 1 root@unraid
#
# The package is made from source/nut-dw with Slackware's own makepkg, which every Unraid host
# has (/sbin/makepkg). It runs on the host given as ssh target (default root@unraid, or
# $NUT_BUILD_HOST), under /tmp there; nothing is installed and the temp directory is removed.
#
# Why makepkg and not tar: installpkg only runs install/doinst.sh when the package lists it as
# exactly "install/doinst.sh". An archive made with "tar -C source/nut-dw -cJf ... ." lists
# "./install/doinst.sh" instead, so doinst.sh is silently skipped. On a running system that goes
# unnoticed (/etc/nut is already populated), but after a reboot /etc/nut stays empty and NUT
# cannot start ("Can't open /etc/nut/ups.conf"). The layout check below refuses such a package.
#
# The result (+ .md5) lands in archive/. Update plgNAME/plgMD5/version in plugin/nut-dw.plg.

VER=$1; BUILD=${2:-1}; HOST=${3:-${NUT_BUILD_HOST:-root@unraid}}
[[ "$VER" =~ ^[0-9]{4}\.[0-9]{2}\.[0-9]{2}[a-z]?$ ]] && [[ "$BUILD" =~ ^[0-9]+$ ]] || { sed -n '3,6p' "$0"; exit 1; }

ROOT=$(cd "$(dirname "$0")/.." && pwd) || exit 1
SRC="$ROOT/source/nut-dw"; OUT="$ROOT/archive"
PKG="nut-plugin-$VER-x86_64-$BUILD.txz"
[ -f "$SRC/install/doinst.sh" ] || { echo "missing $SRC/install/doinst.sh"; exit 1; }
[ -e "$OUT/$PKG" ] && { echo "$OUT/$PKG already exists; bump the version or build number"; exit 1; }

W=$(ssh "$HOST" mktemp -d /tmp/nut-plugin-build.XXXXXX) || exit 1
trap 'ssh "$HOST" "rm -rf $W"' EXIT

# ship the tree (COPYFILE_DISABLE keeps macOS ._ metadata files out), then let makepkg pack it
# as root:root; -l n: no symlink->doinst conversion, -c n: keep the modes as they are in git
# Both ends of the pipe are checked: a local tar error (e.g. an unreadable file) can still produce
# a well-formed but incomplete stream that the remote side would happily pack.
COPYFILE_DISABLE=1 tar -C "$SRC" -cf - . | ssh "$HOST" "mkdir $W/tree && tar -C $W/tree -xf - \
    && chown -R root:root $W/tree && cd $W/tree && makepkg -l n -c n $W/$PKG >/dev/null 2>&1"
ST=("${PIPESTATUS[@]}")
[ "${ST[0]}" -eq 0 ] || { echo "ERROR: local tar of $SRC failed (status ${ST[0]})"; exit 1; }
[ "${ST[1]}" -eq 0 ] || { echo "ERROR: remote extract/makepkg failed (status ${ST[1]})"; exit 1; }
scp -q "$HOST:$W/$PKG" "$OUT/$PKG" || exit 1

# layout check: no "./"-prefixed members besides the root entry, and doinst.sh where installpkg looks.
# The listing is read once into a variable (not piped into grep -q, whose early exit could SIGPIPE tar).
LIST=$(tar -tJf "$OUT/$PKG") || { echo "ERROR: cannot list $PKG"; rm -f "$OUT/$PKG"; exit 1; }
if grep -q '^\./.' <<<"$LIST"; then
    echo "ERROR: $PKG has ./-prefixed paths; installpkg would skip install/doinst.sh"; rm -f "$OUT/$PKG"; exit 1
fi
if ! grep -qx 'install/doinst.sh' <<<"$LIST"; then
    echo "ERROR: $PKG does not list install/doinst.sh"; rm -f "$OUT/$PKG"; exit 1
fi

# checksum: md5sum (Linux) or md5 -r (macOS); refuse anything that isn't a 32-hex digest
SUM=$(cd "$OUT" && { md5sum "$PKG" 2>/dev/null || md5 -r "$PKG"; } | awk '{print $1}')
[[ "$SUM" =~ ^[0-9a-f]{32}$ ]] || { echo "ERROR: could not compute md5 of $PKG"; rm -f "$OUT/$PKG.md5"; exit 1; }
echo "$SUM  $PKG" > "$OUT/$PKG.md5" || { echo "ERROR: cannot write $OUT/$PKG.md5"; rm -f "$OUT/$PKG.md5"; exit 1; }
cat "$OUT/$PKG.md5"
