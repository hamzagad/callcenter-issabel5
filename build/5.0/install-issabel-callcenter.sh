#!/bin/bash

# Unified installation script for Issabel CallCenter
# Usage:
#   ./install-issabel-callcenter-unified.sh          # Install from GitHub
#   ./install-issabel-callcenter-unified.sh --local  # Install from local directory

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color
RELEASE='4.0.0-6'
GITHUB_ACCOUNT='ISSABELPBX'

# Parse arguments
LOCAL_INSTALL=false
if [ "$1" = "--local" ] || [ "$1" = "-l" ]; then
    LOCAL_INSTALL=true
fi

# Check Asterisk version
VERSION=$(asterisk -rx "core show version" 2>/dev/null | awk '{print $2}' | cut -d. -f 1)

if [ -z "$VERSION" ]; then
    echo -e "${RED}Error: Cannot detect Asterisk version. Is Asterisk running?${NC}"
    exit 1
fi

if [ "$VERSION" = "11" ]; then
    echo -e "${RED}Error: Issabel CallCenter ${RELEASE} is NOT compatible with Asterisk 11.${NC}"
    echo -e "${RED}Please upgrade to Asterisk 16 or 18.${NC}"
    exit 1
fi

if [ "$VERSION" != "18" ] && [ "$VERSION" != "16" ]; then
    echo -e "${YELLOW}Warning: Issabel CallCenter ${RELEASE} is tested with Asterisk 18. Detected version: $VERSION${NC}"
    echo -e "${YELLOW}Proceeding with installation, but some features may not work correctly.${NC}"
    echo
fi

# Determine source directory
if [ "$LOCAL_INSTALL" = true ]; then
    # Find the repository root (two levels up from this script)
    SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

    if [ ! -f "$REPO_ROOT/menu.xml" ]; then
        echo -e "${RED}Error: Cannot find repository root. Expected menu.xml at $REPO_ROOT${NC}"
        exit 1
    fi

    echo -e "${GREEN}Installing Issabel CallCenter from local directory: $REPO_ROOT${NC}"
    WORK_DIR="$REPO_ROOT"
else
    echo -e "${GREEN}Installing Issabel CallCenter from GitHub: ${GITHUB_ACCOUNT}/callcenter-issabel5${NC}"

    # Install git if not present
    if ! command -v git &> /dev/null; then
        echo "Installing git..."
        dnf -y install git || yum -y install git
    fi

    # Clone repository
    cd /usr/src
    rm -rf callcenter
    echo "Cloning repository..."
    if ! git clone "https://github.com/${GITHUB_ACCOUNT}/callcenter-issabel5.git" callcenter; then
        echo -e "${RED}Error: Failed to clone repository${NC}"
        exit 1
    fi
    WORK_DIR="/usr/src/callcenter"
fi

cd "$WORK_DIR"

echo "Installing modules..."
# Install modules (force overwrite)
chown asterisk.asterisk modules/* -R
/bin/cp -prf modules/* /var/www/html/modules/

echo "Installing dialer..."
# Install dialer
mkdir -p /opt/issabel/dialer/
chmod 755 /opt/issabel/dialer/
/bin/cp -rf setup/dialer_process/dialer/ /opt/issabel/
chmod +x /opt/issabel/dialer/dialerd

# Install init script
mkdir -p /etc/rc.d/init.d/
/bin/cp -f setup/dialer_process/issabeldialer /etc/rc.d/init.d/
chmod +x /etc/rc.d/init.d/issabeldialer

# Install logrotate config
mkdir -p /etc/logrotate.d/
/bin/cp -f setup/issabeldialer.logrotate /etc/logrotate.d/issabeldialer

# Install DNC script
/bin/cp -f setup/usr/bin/issabel-callcenter-local-dnc /usr/bin/

# Set ownership
chown asterisk.asterisk /opt/issabel -R

echo "Installing module installer files..."
# Install module installer files
rm -rf /usr/share/issabel/module_installer/callcenter/
mkdir -p /usr/share/issabel/module_installer/callcenter/
/bin/cp -rf setup/ /usr/share/issabel/module_installer/callcenter/
/bin/cp -f menu.xml /usr/share/issabel/module_installer/callcenter/
/bin/cp -f CHANGELOG /usr/share/issabel/module_installer/callcenter/

# Merge menu
echo "Merging menu..."
issabel-menumerge /usr/share/issabel/module_installer/callcenter/menu.xml

# Install SSE Apache config for PHP-FPM compatibility
/bin/cp -f /usr/share/issabel/module_installer/callcenter/setup/issabel-sse.conf /etc/httpd/conf.d/
systemctl reload httpd 2>/dev/null || true

# Run database installer
echo "Running database installer..."
mkdir -p /tmp/new_module/callcenter
/bin/cp -rf /usr/share/issabel/module_installer/callcenter/* /tmp/new_module/callcenter/
chown -R asterisk.asterisk /tmp/new_module/callcenter

php /tmp/new_module/callcenter/setup/installer.php
rm -rf /tmp/new_module

# Set shell for user asterisk (required for dialer to work)
echo "Configuring asterisk user shell..."
if ! rpm -q util-linux-user &>/dev/null; then
    dnf install -y util-linux-user 2>/dev/null || yum install -y util-linux-user 2>/dev/null || true
fi

# Use usermod instead of chsh to avoid interactive prompts
if id asterisk &>/dev/null; then
    usermod -s /bin/bash asterisk 2>/dev/null || chsh -s /bin/bash asterisk </dev/null 2>/dev/null || true
fi

# Reload systemd to recognize the init script
systemctl daemon-reload 2>/dev/null || true

# Add dialer to startup scripts, and enable it by default
echo "Enabling issabeldialer service..."
chkconfig --add issabeldialer 2>/dev/null || true
chkconfig --level 2345 issabeldialer on 2>/dev/null || true

# Restart dialer if already running, otherwise start it
if systemctl is-active --quiet issabeldialer 2>/dev/null || service issabeldialer status >/dev/null 2>&1; then
    echo -e "${GREEN}Restarting issabeldialer service...${NC}"
    service issabeldialer restart
else
    echo -e "${GREEN}Starting issabeldialer service...${NC}"
    service issabeldialer start
fi

# Clean up cloned repository if installed from GitHub
if [ "$LOCAL_INSTALL" = false ]; then
    rm -rf /usr/src/callcenter
fi

echo
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}Issabel CallCenter ${RELEASE} installation complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo
