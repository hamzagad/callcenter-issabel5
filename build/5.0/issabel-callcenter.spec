%define modname callcenter

Summary: Issabel Call Center
Name:    issabel-%{modname}
Version: 5.0.0
Release: 2
License: GPL
Group:   Applications/System
Source0: %{modname}_%{version}-%{release}.tgz
BuildRoot: %{_tmppath}/%{name}-%{version}-root
BuildArch: noarch
Requires(pre): issabel-framework >= 2.4.0-1
Requires: issabelPBX
Requires: php-mbstring

Obsoletes: elastix-callcenter

%description
Issabel Call Center module - predictive dialer and call center solution.
Supports Asterisk 11 (chan_agent), 13, and 18 (app_agent_pool).

%prep
%setup -n %{name}_%{version}-%{release}

%install
rm -rf $RPM_BUILD_ROOT

# Files provided by all Issabel modules
mkdir -p    $RPM_BUILD_ROOT/var/www/html/
mv modules/ $RPM_BUILD_ROOT/var/www/html/

# Additional (module-specific) files that can be handled by RPM
mkdir -p $RPM_BUILD_ROOT/opt/issabel/
mv setup/dialer_process/dialer/ $RPM_BUILD_ROOT/opt/issabel/
chmod +x $RPM_BUILD_ROOT/opt/issabel/dialer/dialerd
mkdir -p $RPM_BUILD_ROOT/etc/rc.d/init.d/
mv setup/dialer_process/issabeldialer $RPM_BUILD_ROOT/etc/rc.d/init.d/
chmod +x $RPM_BUILD_ROOT/etc/rc.d/init.d/issabeldialer
rmdir setup/dialer_process
mkdir -p $RPM_BUILD_ROOT/etc/logrotate.d/
mv setup/issabeldialer.logrotate $RPM_BUILD_ROOT/etc/logrotate.d/issabeldialer
mv setup/usr $RPM_BUILD_ROOT/usr

# SSE Apache config for PHP-FPM (Rocky 8+)
mkdir -p $RPM_BUILD_ROOT/etc/httpd/conf.d/
cp setup/issabel-sse.conf $RPM_BUILD_ROOT/etc/httpd/conf.d/

# The following folder should contain all the data that is required by the installer,
# that cannot be handled by RPM.
mkdir -p    $RPM_BUILD_ROOT/usr/share/issabel/module_installer/%{name}-%{version}-%{release}/
mv setup/   $RPM_BUILD_ROOT/usr/share/issabel/module_installer/%{name}-%{version}-%{release}/
mv menu.xml $RPM_BUILD_ROOT/usr/share/issabel/module_installer/%{name}-%{version}-%{release}/
mv CHANGELOG $RPM_BUILD_ROOT/usr/share/issabel/module_installer/%{name}-%{version}-%{release}/

%post

# Run installer script to fix up ACLs and add module to Issabel menus.
issabel-menumerge /usr/share/issabel/module_installer/%{name}-%{version}-%{release}/menu.xml

# The installer script expects to be in /tmp/new_module
mkdir -p /tmp/new_module/%{modname}
cp -r /usr/share/issabel/module_installer/%{name}-%{version}-%{release}/* /tmp/new_module/%{modname}/
chown -R asterisk.asterisk /tmp/new_module/%{modname}

php /tmp/new_module/%{modname}/setup/installer.php
rm -rf /tmp/new_module

# Install SSE Apache config only on Rocky/PHP-FPM systems
if [ -f /etc/rocky-release ]; then
    systemctl reload httpd 2>/dev/null || true
else
    # Remove SSE config on non-Rocky systems (CentOS 7 uses mod_php)
    rm -f /etc/httpd/conf.d/issabel-sse.conf
fi

# Set shell for user asterisk (required for dialer to work)
if command -v usermod >/dev/null 2>&1; then
    usermod -s /bin/bash asterisk 2>/dev/null || true
else
    chsh -s /bin/bash asterisk 2>/dev/null || true
fi

# Reload systemd to recognize the init script
systemctl daemon-reload 2>/dev/null || true

# Add dialer to startup scripts, and enable it by default
chkconfig --add issabeldialer
chkconfig --level 2345 issabeldialer on

# Fix incorrect permissions left by earlier versions of RPM
chown -R asterisk.asterisk /opt/issabel/dialer

# To update smarty (tpl updates)
rm -rf /var/www/html/var/templates_c/*

# Remove obsolete modules
issabel-menuremove rep_agent_connection_time 2>/dev/null || true

if [ $1 -eq 1  ] ; then # install
    if [ x`pidof mysqld` == "x"  ] ; then
        # mysql is not running, delay db creation
        cp /usr/share/issabel/module_installer/%{name}-%{version}-%{release}/setup/firstboot_call_center.sql /var/spool/issabel-mysqldbscripts/08-call_center.sql
    fi
fi

%clean
rm -rf $RPM_BUILD_ROOT

%preun
if [ $1 -eq 0 ] ; then # Check to tell apart update and uninstall
  echo "Removing CallCenter menus..."
  if [ -e /usr/bin/issabel-menuremove ] ; then
    issabel-menuremove "call_center"
  else
    echo "No issabel-menuremove found, might have stale menu in web interface."
  fi
  chkconfig --del issabeldialer
  # Remove SSE config
  rm -f /etc/httpd/conf.d/issabel-sse.conf
  systemctl reload httpd 2>/dev/null || true
fi

%files
%defattr(-, asterisk, asterisk)
/opt/issabel/dialer
%defattr(-, root, root)
%{_localstatedir}/www/html/*
%{_datadir}/issabel/module_installer/*
/opt/issabel/dialer/*
%{_sysconfdir}/rc.d/init.d/issabeldialer
%{_sysconfdir}/logrotate.d/issabeldialer
%config(noreplace) %{_sysconfdir}/httpd/conf.d/issabel-sse.conf
%defattr(0775, root, root)
%{_bindir}/issabel-callcenter-local-dnc

%changelog
