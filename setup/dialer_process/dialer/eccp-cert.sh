#!/bin/bash

# ECCP TLS certificate management for the Issabel Call Center dialer.
#
# The ECCP listener (port 20005) is TLS-only. The dialer runs as the
# unprivileged `asterisk` user and therefore needs its own readable copy of a
# certificate and key: Issabel's Apache material is 0600 root.
#
# Clients do NOT verify this certificate (encryption only, no server
# authentication), so its subject, SANs and expiry are irrelevant. That is what
# keeps the dialer reachable as localhost, as a hostname, or as a bare LAN IP
# with no local DNS.
#
# Usage:
#   eccp-cert.sh install [--force]   # place cert+key (skips if already present)
#   eccp-cert.sh remove              # delete cert+key
#
# Environment:
#   ECCP_CERT_MODE=generate|copy     # default: generate a dedicated ECDSA
#                                    #   certificate for the dialer
#                                    # copy: reuse Issabel's Apache certificate
#                                    #   (duplicates the web server's private key)

CERT_DIR="/etc/issabel/dialer"
CERT_FILE="$CERT_DIR/eccp.pem"
KEY_FILE="$CERT_DIR/eccp.key"

SRC_CERT="/etc/pki/tls/certs/localhost.crt"
SRC_KEY="/etc/pki/tls/private/localhost.key"

CERT_MODE="${ECCP_CERT_MODE:-generate}"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m'

# ECDSA P-256 rather than RSA: it pairs with the TLS 1.3 AEAD suites this
# listener requires, and its handshake signature is ~29x cheaper than RSA-2048
# on the single-threaded ECCP process.
#
# A plain self-signed certificate with no SAN is deliberate. Clients do not
# check names, and a client that wants man-in-the-middle protection pins this
# certificate by its SHA-256 fingerprint (printed below), which depends on
# neither names nor addresses. Generating rather than copying Issabel's Apache
# certificate keeps the web server's private key out of asterisk-readable files.
generate_selfsigned() {
    if ! command -v openssl >/dev/null 2>&1; then
        echo -e "${RED}Error: openssl is required to generate an ECCP certificate${NC}"
        return 1
    fi
    openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:prime256v1 \
        -sha256 -nodes -days 3650 \
        -subj "/C=--/O=Issabel/OU=CallCenter/CN=issabel-eccp" \
        -keyout "$KEY_FILE" -out "$CERT_FILE" >/dev/null 2>&1
}

do_install() {
    local force=false
    [ "$1" = "--force" ] && force=true

    if [ -f "$CERT_FILE" ] && [ -f "$KEY_FILE" ] && [ "$force" = false ]; then
        echo -e "${GREEN}ECCP TLS certificate already present at $CERT_FILE - keeping it${NC}"
        return 0
    fi

    mkdir -p "$CERT_DIR" || return 1

    local mode="$CERT_MODE"
    if [ "$mode" = "copy" ] && { [ ! -r "$SRC_CERT" ] || [ ! -r "$SRC_KEY" ]; }; then
        echo -e "${YELLOW}Issabel certificate not found at $SRC_CERT - generating a dedicated one instead${NC}"
        mode="generate"
    fi

    if [ "$mode" = "copy" ]; then
        /bin/cp -f "$SRC_CERT" "$CERT_FILE" || return 1
        /bin/cp -f "$SRC_KEY"  "$KEY_FILE"  || return 1
        echo "Copied Issabel certificate for ECCP use"
    else
        if ! generate_selfsigned; then
            echo -e "${YELLOW}Could not generate a certificate - falling back to Issabel's${NC}"
            [ -r "$SRC_CERT" ] && [ -r "$SRC_KEY" ] || return 1
            /bin/cp -f "$SRC_CERT" "$CERT_FILE" || return 1
            /bin/cp -f "$SRC_KEY"  "$KEY_FILE"  || return 1
        else
            echo "Generated a dedicated ECDSA P-256 certificate for ECCP use"
        fi
    fi

    chown asterisk:asterisk "$CERT_DIR" "$CERT_FILE" "$KEY_FILE" || return 1
    chmod 0750 "$CERT_DIR"
    chmod 0444 "$CERT_FILE"
    chmod 0400 "$KEY_FILE"

    # The dialer runs as asterisk - prove it can actually read the key, since a
    # TLS-only listener that cannot load its key refuses to start.
    if ! su -s /bin/bash asterisk -c "test -r '$KEY_FILE'" 2>/dev/null; then
        echo -e "${RED}Error: $KEY_FILE is not readable by the asterisk user${NC}"
        return 1
    fi

    echo -e "${GREEN}ECCP TLS certificate installed at $CERT_FILE${NC}"

    # The fingerprint is what an administrator distributes to remote clients
    # that want to pin this certificate and defeat a man-in-the-middle.
    if command -v openssl >/dev/null 2>&1; then
        echo "SHA-256 fingerprint: $(openssl x509 -in "$CERT_FILE" -noout -fingerprint -sha256 2>/dev/null | cut -d= -f2)"
    fi
    return 0
}

do_remove() {
    rm -f "$CERT_FILE" "$KEY_FILE"
    # Only remove the directories we own, and only when empty, so an unrelated
    # /etc/issabel is never clobbered.
    rmdir "$CERT_DIR" 2>/dev/null
    rmdir /etc/issabel 2>/dev/null
    echo "Removed ECCP TLS certificate"
    return 0
}

case "$1" in
    install) do_install "$2" ;;
    remove)  do_remove ;;
    *)       echo "Usage: $0 {install [--force]|remove}" ; exit 1 ;;
esac
