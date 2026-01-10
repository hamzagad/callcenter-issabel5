#!/bin/bash

# Local installation script for Issabel CallCenter
# This script installs from the local repository directory
# without cloning from GitHub. Use this for development/testing.

RED='\033[0;31m'
GREEN='\033[0;32m'
NC='\033[0m' # No Color

# Find the repository root (two levels up from this script)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

if [ ! -f "$REPO_ROOT/menu.xml" ]; then
    echo -e "${RED}Error: Cannot find repository root. Expected menu.xml at $REPO_ROOT${NC}"
    exit 1
fi

echo -e "${GREEN}Installing Issabel CallCenter from local directory: $REPO_ROOT${NC}"

VERSION=$(asterisk -rx "core show version" 2>/dev/null | awk '{print $2}' | cut -d\. -f 1)

if [ "$VERSION" != "11" ] && [ "$VERSION" != "16" ] && [ "$VERSION" != "18" ]; then
    echo
    echo -e "${RED}Warning: Issabel CallCenter is tested with Asterisk 11/16/18. Detected version: $VERSION${NC}"
    echo
fi

cd "$REPO_ROOT"

# Install modules (force overwrite)
chown asterisk.asterisk modules/* -R
cp -prf modules/* /var/www/html/modules/

# Install dialer
mkdir -p /opt/issabel/dialer/
chmod 755 /opt/issabel/dialer/
cp -rf setup/dialer_process/dialer/ /opt/issabel/
chmod +x /opt/issabel/dialer/dialerd

# Install init script
cp -f setup/dialer_process/issabeldialer /etc/rc.d/init.d/
chmod +x /etc/rc.d/init.d/issabeldialer

# Install logrotate config
cp -f setup/issabeldialer.logrotate /etc/logrotate.d/issabeldialer

# Install DNC script
cp -f setup/usr/bin/issabel-callcenter-local-dnc /usr/bin/

# Set ownership
chown asterisk.asterisk /opt/issabel -R

# Install module installer files
rm -rf /usr/share/issabel/module_installer/callcenter/
mkdir -p /usr/share/issabel/module_installer/callcenter/
cp -rf setup/ /usr/share/issabel/module_installer/callcenter/
cp -f menu.xml /usr/share/issabel/module_installer/callcenter/
cp -f CHANGELOG /usr/share/issabel/module_installer/callcenter/

# Merge menu
issabel-menumerge /usr/share/issabel/module_installer/callcenter/menu.xml

# Install SSE Apache config for PHP-FPM compatibility
cp -f /usr/share/issabel/module_installer/callcenter/setup/issabel-sse.conf /etc/httpd/conf.d/
systemctl reload httpd 2>/dev/null

# Run database installer
mkdir -p /tmp/new_module/callcenter
cp -rf /usr/share/issabel/module_installer/callcenter/* /tmp/new_module/callcenter/
chown -R asterisk.asterisk /tmp/new_module/callcenter

php /tmp/new_module/callcenter/setup/installer.php
rm -rf /tmp/new_module

# Be sure to set shell for user asterisk
dnf install util-linux-user -y >/dev/null 2>&1
chsh -s /bin/bash asterisk 2>&1 >/dev/null

# Add dialer to startup scripts, and enable it by default
chkconfig --add issabeldialer
chkconfig --level 2345 issabeldialer on

# Restart dialer if already running, otherwise start it
if systemctl is-active --quiet issabeldialer 2>/dev/null || service issabeldialer status >/dev/null 2>&1; then
    echo -e "${GREEN}Restarting issabeldialer service...${NC}"
    service issabeldialer restart
else
    echo -e "${GREEN}Starting issabeldialer service...${NC}"
    service issabeldialer start
fi

echo -e "${GREEN}Installation complete!${NC}"
