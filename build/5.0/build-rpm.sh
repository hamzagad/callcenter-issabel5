#!/bin/bash
# build-rpm.sh - build the issabel-callcenter5 RPM from this checkout, one
# command. This is the entry point a CI job calls on a version bump.
#
#   bash build/5.0/build-rpm.sh
#
# The package name is issabel-callcenter5 on purpose: it is neither an
# alternative nor an update for issabel-callcenter, the previous package.
# The version and the release both come from the repository's VERSION file
# (the single source of truth; the spec carries no version literal): the
# first field is the Version and the rest is the Release, plus the OS tag
# the build host adds, so a bump in any field always produces an upgrade.
# Environment overrides:
#   CC_RPM_TOPDIR   where to build (default <repo>/build/rpm, gitignored);
#                   SOURCES, BUILD and RPMS land under it
# CC_RPM_RELEASE is no longer used and is refused when set: the release
# comes from VERSION.
#
# The tarball is staged from the checkout with /bin/cp -a, never git archive:
# git normalises directory modes to 0775, and those modes would reach the
# package's directories through the buildroot.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck disable=SC1091
source "$SCRIPT_DIR/callcenter-common.sh"

REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
for f in menu.xml VERSION CHANGELOG_OLD.md modules setup; do
    [ -e "$REPO_ROOT/$f" ] || {
        echo "Error: $REPO_ROOT is not a full checkout (no $f)." >&2
        exit 1
    }
done

V="$(cc_read_version "$REPO_ROOT")" || V=''
if ! cc_version_valid "$V"; then
    echo "Error: no readable release number in $REPO_ROOT/VERSION (${V:-none})." >&2
    exit 1
fi
case "$V" in
    *.*) ;;
    *)
        echo "Error: $REPO_ROOT/VERSION ($V) has no release field; expected N.N (major.minor, optionally more fields)." >&2
        exit 1 ;;
esac

if [ -n "${CC_RPM_RELEASE:-}" ]; then
    echo "Error: CC_RPM_RELEASE is no longer used: the release comes from VERSION." >&2
    exit 1
fi

# The split the user asked for: the first field is the Version and the rest
# is the Release (the dist tag is appended by rpmbuild).
cc_version="${V%%.*}"
cc_release="${V#*.}"

DATE="$(LC_ALL=C date +'%a %b %d %Y')"
TOPDIR="${CC_RPM_TOPDIR:-$REPO_ROOT/build/rpm}"
mkdir -p "$TOPDIR"/BUILD "$TOPDIR"/SOURCES "$TOPDIR"/SPECS "$TOPDIR"/RPMS "$TOPDIR"/SRPMS

# Stage exactly what ships: the web modules, setup/, menu.xml, the frozen
# pre-release changelog and the release number. Nothing else from the
# checkout reaches the tarball. The staging directory and the tarball carry
# the full VERSION.
STAGE_PARENT="$TOPDIR/stage"
STAGE="$STAGE_PARENT/issabel-callcenter5_${V}"
/bin/rm -rf "$STAGE_PARENT"
mkdir -p "$STAGE"
/bin/cp -a "$REPO_ROOT/modules" "$REPO_ROOT/setup" "$REPO_ROOT/menu.xml" \
          "$REPO_ROOT/CHANGELOG_OLD.md" "$REPO_ROOT/VERSION" "$STAGE/"

TARBALL="$TOPDIR/SOURCES/callcenter5_${V}.tgz"
tar -C "$STAGE_PARENT" -czf "$TARBALL" "issabel-callcenter5_${V}"

rpmbuild -bb \
    --define "_topdir $TOPDIR" \
    --define "cc_version $cc_version" \
    --define "cc_release $cc_release" \
    --define "cc_date $DATE" \
    "$SCRIPT_DIR/issabel-callcenter.spec"

# Exactly one noarch RPM, named after the version just built.
RPM_BUILT="$(ls "$TOPDIR"/RPMS/noarch/issabel-callcenter5-${cc_version}-${cc_release}*.noarch.rpm)"
echo "RPM: $RPM_BUILT"
