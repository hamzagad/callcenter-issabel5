#!/bin/bash

# Unified installation script for Issabel CallCenter
# Usage:
#   ./install-issabel-callcenter.sh          # Install from GitHub
#   ./install-issabel-callcenter.sh --local  # Install from local directory

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
NC='\033[0m' # No Color
RELEASE='5.0.0-1'
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
    echo -e "${YELLOW}Info: Detected Asterisk 11. Using chan_agent compatibility mode.${NC}"
    echo -e "${YELLOW}  - Agent authentication: via Asterisk (password in agents.conf)${NC}"
    echo -e "${YELLOW}  - Agent interface: Agent/XXXX${NC}"
    echo -e "${YELLOW}  - Agent logout: Agentlogoff AMI command${NC}"
elif [ "$VERSION" = "13" ] || [ "$VERSION" = "16" ] || [ "$VERSION" = "18" ]; then
    echo -e "${GREEN}Info: Detected Asterisk $VERSION. Using app_agent_pool mode.${NC}"
    echo -e "${GREEN}  - Agent authentication: via ECCP/database${NC}"
    echo -e "${GREEN}  - Agent interface: Local/XXXX@agents${NC}"
    echo -e "${GREEN}  - Agent logout: Hangup login channel${NC}"
else
    echo -e "${YELLOW}Warning: Issabel CallCenter ${RELEASE} is tested with Asterisk 11/13/18. Detected version: $VERSION${NC}"
    echo -e "${YELLOW}Proceeding with installation, but some features may not work correctly.${NC}"
fi
echo

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

echo "Patching dashboard ProcessesStatus applet..."
DASHBOARD_DIR="/var/www/html/modules/dashboard/applets/ProcessesStatus"
DASHBOARD_INDEX="$DASHBOARD_DIR/index.php"

if [ -f "$DASHBOARD_INDEX" ]; then
    # Copy the dialer icon
    /bin/cp -f "$WORK_DIR/setup/icon_headphones.png" "$DASHBOARD_DIR/images/"

    # 1. Add Dialer icon mapping (after 'Apache' => 'icon_www.png')
    if ! grep -q "'Dialer'" "$DASHBOARD_INDEX"; then
        sed -i "/'Apache'.*=>.*'icon_www.png'/a\\            'Dialer'    =>  'icon_headphones.png'," "$DASHBOARD_INDEX"
    fi

    # 2. Add Dialer service mapping in _controlServicio (after 'Apache' => 'httpd')
    if ! grep -q "'Dialer'.*=>.*'issabeldialer'" "$DASHBOARD_INDEX"; then
        sed -i "/'Apache'.*=>.*'httpd'/a\\            'Dialer'    =>  'issabeldialer'," "$DASHBOARD_INDEX"
    fi

    # 3. Add Dialer status detection (after Apache status line in getStatusServices)
    if ! grep -q 'dialerd.pid' "$DASHBOARD_INDEX"; then
        sed -i '/\$arrSERVICES\["Apache"\]\["name_service"\].*=.*"Web Server"/a\
\
        $arrSERVICES["Dialer"]["status_service"]   = $this->_existPID_ByFile("/opt/issabel/dialer/dialerd.pid","issabeldialer");\
        $arrSERVICES["Dialer"]["activate"]     = $this->_isActivate("issabeldialer");\
        $arrSERVICES["Dialer"]["name_service"]     = "Issabel Call Center Service";' "$DASHBOARD_INDEX"
    fi

    # 4. Fix _existService() to check /etc/systemd/system/ (for systemd services installed in /etc)
    # Check if the fix is already applied by looking for the specific pattern
    if ! grep -q 'file_exists("/etc/systemd/system/{$ns}.service")' "$DASHBOARD_INDEX"; then
        sed -i 's|if (file_exists("/usr/lib/systemd/system/{$ns}.service"))|if (file_exists("/etc/systemd/system/{$ns}.service"))\n                return TRUE;\n            if (file_exists("/usr/lib/systemd/system/{$ns}.service"))|' "$DASHBOARD_INDEX"
        echo "  - Added _existService() fix for /etc/systemd/system/ detection"
    else
        echo "  - _existService() fix already present, skipping"
    fi

    echo -e "${GREEN}Dashboard patched successfully${NC}"
else
    echo -e "${YELLOW}Warning: Dashboard applet not found at $DASHBOARD_INDEX - skipping patch${NC}"
fi

echo "Installing dialer..."
# Install dialer
mkdir -p /opt/issabel/dialer/
chmod 755 /opt/issabel/dialer/
/bin/cp -rf setup/dialer_process/dialer/ /opt/issabel/
chmod +x /opt/issabel/dialer/dialerd

# Install systemd service file
/bin/cp -f setup/dialer_process/issabeldialer.service /etc/systemd/system/
systemctl daemon-reload

# Install logrotate config
mkdir -p /etc/logrotate.d/
/bin/cp -f setup/issabeldialer.logrotate /etc/logrotate.d/issabeldialer

# Create callcenter module log directory
mkdir -p /var/log/callcenter-module/
chown asterisk:asterisk /var/log/callcenter-module/
chmod 750 /var/log/callcenter-module/

# Install web modules logrotate config
/bin/cp -f setup/callcenter-modules.logrotate /etc/logrotate.d/callcenter-modules

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

# Install SSE Apache config only on Rocky/PHP-FPM systems
if [ -f /etc/rocky-release ]; then
    /bin/cp -f /usr/share/issabel/module_installer/callcenter/setup/issabel-sse.conf /etc/httpd/conf.d/
    systemctl reload httpd 2>/dev/null || true
fi

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

# Enable and start the systemd service
echo "Enabling issabeldialer service..."
systemctl enable issabeldialer

# Restart dialer if already running, otherwise start it
if systemctl is-active --quiet issabeldialer; then
    echo -e "${GREEN}Restarting issabeldialer service...${NC}"
    systemctl restart issabeldialer
else
    echo -e "${GREEN}Starting issabeldialer service...${NC}"
    systemctl start issabeldialer
fi

# Reload Asterisk
asterisk -rx'core reload' 2>/dev/null || true

# Clean up cloned repository if installed from GitHub
if [ "$LOCAL_INSTALL" = false ]; then
    rm -rf /usr/src/callcenter
fi

echo
echo -e "${GREEN}============================================${NC}"
echo -e "${GREEN}Issabel CallCenter ${RELEASE} installation complete!${NC}"
echo -e "${GREEN}============================================${NC}"
echo
