#!/bin/bash
#
# Reproducible build of the NUT Slackware package used by the nut-dw plugin.
#
# Usage: build/build-nut.sh <version|git-ref> <build> <stable|master> <15.0|current> [ssh-target]
#   e.g. build/build-nut.sh 2.8.5 2 stable current
#        build/build-nut.sh master 1 master 15.0 root@unraid
#
# stable: downloads the release tarball for <version> (e.g. 2.8.5).
# master: clones networkupstools/nut and checks out <git-ref>; the package version is
#         taken from the checked-out tree (configure.ac), so <git-ref> may be a branch/tag/sha.
# 15.0 builds the ".ssl11" package (Unraid 6.10-6.12), current builds ".ssl31" (Unraid 7.x).
#
# The build runs in a throwaway aclemons/slackware container on the docker host given as
# ssh target (default root@unraid, or $NUT_BUILD_HOST), under /tmp there. The result
# (nut-<ver>-x86_64-<build><flavor>.<ssl>.txz + .md5) lands in packages/. Nothing is installed
# on the host; the downloaded base image is left in its docker cache (docker rmi to drop it).
# freeipmi, powerman and libmodbus are built in the container only so NUT links against them.
# The GPG .asc signatures of the upstream packages cannot be reproduced and are not made.

REF=$1; BUILD=$2; FLAVOR=$3; SLACK=$4; HOST=${5:-${NUT_BUILD_HOST:-root@unraid}}
case "$FLAVOR:$SLACK" in
    stable:15.0|master:15.0) SSL=ssl11 ;;
    stable:current|master:current) SSL=ssl31 ;;
    *) sed -n '3,8p' "$0"; exit 1 ;;
esac
[ -n "$REF" ] && [ -n "$BUILD" ] || exit 1

OUT=$(cd "$(dirname "$0")/../packages" && pwd) || exit 1
W=$(ssh "$HOST" mktemp -d /tmp/nut-build.XXXXXX) || exit 1
trap 'ssh "$HOST" "docker rm -f nut-build-$$ >/dev/null 2>&1; rm -rf $W"' EXIT

# current ships a newer net-snmp (libnetsnmp.so.45) than the one the plugin installs on Unraid 7
# (.so.40), so build against the plugin's own package
[ "$SLACK" = current ] && scp -q "$OUT"/net-snmp-5.9.4-x86_64-1.txz "$HOST:$W/" || true

# the part after __INNER__ runs inside the container
sed '1,/^__INNER__$/d' "$0" | ssh "$HOST" docker run -i --rm --name nut-build-$$ -v "$W:/out" \
    -e REF="$REF" -e BUILD="$BUILD" -e FLAVOR="$FLAVOR" -e SLACK="$SLACK" -e SSL="$SSL" \
    "aclemons/slackware:$SLACK" bash -s || exit 1

PKG=$(ssh "$HOST" "cat $W/result")
scp -q "$HOST:$W/$PKG" "$OUT/$PKG" || exit 1
(cd "$OUT" && md5sum "$PKG" > "$PKG.md5" && cat "$PKG.md5")
exit 0

__INNER__
set -eo pipefail
export LC_ALL=C
slackpkg -batch=on -default_answer=y update gpg >/dev/null
slackpkg -batch=on -default_answer=y update >/dev/null
slackpkg -batch=on -default_answer=y install \
    gcc gcc-g++ make binutils glibc kernel-headers autoconf automake libtool m4 pkg-config pkgconf perl \
    python3 git ca-certificates openssl libusb net-snmp neon glib2 libnsl libtirpc gettext-tools \
    gettext elfutils libgcrypt libgpg-error readline file bison flex curl libxml2 pcre2 zlib util-linux guile gc libunistring gmp libffi \
    libproxy duktape libevent avahi libdaemon dbus expat nghttp2 nghttp3 ngtcp2 brotli cyrus-sasl >/dev/null || true  # avahi, libdaemon: only on current
upgradepkg --reinstall /out/net-snmp-*.txz >/dev/null 2>&1 || true   # ABI of the net-snmp the plugin ships
# update-ca-certificates needs run-parts, which the image lacks: build the bundle by hand
cat /usr/share/ca-certificates/mozilla/*.crt > /etc/ssl/certs/ca-certificates.crt
export SSL_CERT_FILE=/etc/ssl/certs/ca-certificates.crt GIT_SSL_CAINFO=/etc/ssl/certs/ca-certificates.crt

# libraries missing from stock Slackware (build environment only, not packaged)
cd /tmp
get() { wget -q "$1" -O - | tar -xz; }  # (a failed download fails the pipeline: pipefail)
# libmodbus needs the USB-capable networkupstools branch (for apc_modbus), as in the plugin's libmodbus package
git clone -q -b rtu_usb https://github.com/networkupstools/libmodbus.git
(cd libmodbus && ./autogen.sh >/dev/null)
get https://ftp.gnu.org/gnu/freeipmi/freeipmi-1.6.20.tar.gz
get https://github.com/chaos/powerman/releases/download/v2.4.4/powerman-2.4.4.tar.gz
# (the --without flags only matter to powerman, we just need its libpowerman; others ignore them)
for d in libmodbus freeipmi-1.6.20 powerman-2.4.4; do
    (cd $d && CFLAGS="-O2 -std=gnu17" ./configure --prefix=/usr --libdir=/usr/lib64 --sysconfdir=/etc --localstatedir=/var \
        --disable-static --without-httppower --without-redfishpower --without-snmppower >/dev/null && make -j"$(nproc)" >/dev/null && make install >/dev/null)
done
if [ ! -e /usr/lib64/libavahi-client.so ]; then   # 15.0 has no avahi (NUT --with-all needs it)
    get https://0pointer.de/lennart/projects/libdaemon/libdaemon-0.14.tar.gz
    get https://github.com/avahi/avahi/releases/download/v0.8/avahi-0.8.tar.gz
    (cd libdaemon-0.14 && ./configure --prefix=/usr --libdir=/usr/lib64 --disable-static >/dev/null &&
        make -j"$(nproc)" >/dev/null && make install >/dev/null)
    (cd avahi-0.8 && ./configure --prefix=/usr --libdir=/usr/lib64 --sysconfdir=/etc --localstatedir=/var \
        --disable-static --disable-gtk --disable-gtk3 --disable-qt3 --disable-qt4 --disable-qt5 \
        --disable-python --disable-mono --disable-gdbm --disable-introspection --disable-manpages \
        --disable-xmltoman --with-distro=none >/dev/null && make -j"$(nproc)" >/dev/null && make install >/dev/null)
fi
ldconfig

# NUT source
if [ "$FLAVOR" = stable ]; then
    get "https://networkupstools.org/source/${REF%.*}/nut-$REF.tar.gz"
    cd "nut-$REF"
else
    git clone -q https://github.com/networkupstools/nut.git nut-src && cd nut-src
    git checkout -q "$REF"
    ./autogen.sh >/dev/null
fi
VER=$(grep -m1 '^AC_INIT' configure.ac | sed 's/.*\[\([0-9][^]]*\)\].*/\1/;s/^\([0-9.]*\).*/\1/')
[ "$FLAVOR" = stable ] && VER=$REF
echo "NUT version: $VER"

SRC=$PWD
# gcc 16 / new glibc const warnings would break -Werror, so demote only that class
export CFLAGS="-O2 -Wno-error=discarded-qualifiers" CXXFLAGS="-O2 -Wno-error=discarded-qualifiers"
./configure --build=x86_64-slackware-linux --prefix=/usr --libdir=/usr/lib64 \
    --libexecdir=/usr/libexec/nut --sbindir=/usr/sbin --sysconfdir=/etc/nut --localstatedir=/var \
    --datadir=/usr/share/nut --mandir=/usr/man --docdir=/usr/doc/nut-$VER --disable-static \
    --enable-option-checking=fatal --with-all --with-linux-i2c=no --with-gpio=no --with-cgi=no \
    --with-nut_monitor=no --with-pynut=no --with-docs=no --enable-docs-changelog=no --with-ssl=no \
    --with-nss=no --with-openssl=no --with-user=root --with-group=root \
    --with-cgipath=/var/www/nut-cgi-bin --with-htmlpath=/var/www/nut \
    --with-drvpath=/usr/libexec/nut --with-statepath=/var/run/nut --with-pidpath=/var/run/nut \
    --with-altpidpath=/var/run/nut --with-udev-dir=/lib/udev --enable-warnings=gcc-hard \
    --enable-Werror=yes >/dev/null
make -j"$(nproc)" >/dev/null
make install DESTDIR=/tmp/pkg >/dev/null

cd /tmp/pkg
find . -name '*.la' -delete
find . -type f -perm -u+x -exec sh -c 'file "$1" | grep -q ELF && strip --strip-unneeded "$1"' _ {} \; 2>/dev/null || true
mkdir -p install usr/man var/run/nut var/state/ups
rmdir usr/doc/nut-*/html-man 2>/dev/null || true   # empty: docs are not built
cp -a "$SRC"/{AUTHORS,COPYING,INSTALL,LICENSE-,MAINTAINERS,NEWS,README,TODO,UPGRADING}* usr/doc/nut-$VER/ 2>/dev/null || true
install -D -m 755 "$SRC/scripts/misc/nut.bash_completion" usr/share/bash-completion/completions/nut
find . -type d -exec chmod 755 {} +
chmod 770 var/run/nut var/state/ups
cat > install/slack-desc <<DESC
   |-----handy-ruler------------------------------------------------------|
nut: NUT - Network UPS Tools (a collection of ups tools)
nut:
nut: The Network UPS Tools is a collection of programs which provide a
nut: common interface for monitoring and administering UPS hardware. It
nut: users a layered approach to connect all the components. Drivers are
nut: provided for a wide assortment of equipment.
nut:
nut:
nut: packaged for davidcoulson/NUT-unRAID on Slackware $SLACK (x86_64)
nut: intended for use on UNRAID installations WITHOUT ANY WARRANTIES
nut:
DESC
PKG=nut-$VER-x86_64-$BUILD$FLAVOR.$SSL.txz
# makepkg -l y turns lib symlinks into doinst.sh; -c n leaves directory permissions alone
makepkg -l y -c n "/out/$PKG"
echo "$PKG" > /out/result
# smoke test: binaries must start with all libraries resolved
LD_LIBRARY_PATH=/tmp/pkg/usr/lib64 /tmp/pkg/usr/sbin/upsd -V
LD_LIBRARY_PATH=/tmp/pkg/usr/lib64 /tmp/pkg/usr/libexec/nut/usbhid-ups -V
