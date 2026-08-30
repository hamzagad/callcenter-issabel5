```
  ___               _          _ 
 |_ _|___ ___  __ _| |__   ___| |
  | |/ __/ __|/ _` | '_ \ / _ \ |
  | |\__ \__ \ (_| | |_) |  __/ |
 |___|___/___/\__,_|_.__/ \___|_|
```

Issabel is an open source distribution and GUI for Unified Communications systems forked from Elastix&copy;

It uses the [Asterisk©](http://www.asterisk.org/ "Asterisk Home Page") open source PBX software as its core.


Callcenter Issabel 5
==========

Call Center Module for Issabel V5, Updated for installing on rocky 8 , with php compatiblle with v7.4 up to v8.0 , In callback and agent modes. 

esta version puede ser instalada en asterisk 18 en IssabelPBX.
This repo is tested with Issabel v5 and Asterisk 18, and it is no logner backward compatible with Issabel 4

#### Version actualizada por la comunidad de Issabel, cualquier duda o problema escribir a https://t.me/IssabelPBXip:
Gracias a la colaboracion de Nicolás Gudiño, Hamza ,Julio pacheco, y comunidad de Issabel en telegram


## Installation Commands
----

```bash
# Full installation (run as root)
cd /usr/src
git clone https://github.com/ISSABELPBX/callcenter-issabel5.git 
cd callcenter-issabel5
# For local installation:
bash build/5.0/install-issabel-callcenter.sh -l
# For production repo installation:
bash build/5.0/install-issabel-callcenter.sh
# To see what the installer does (options, installed files, ECCP TLS certificate):
bash build/5.0/install-issabel-callcenter.sh -h
# To Uninstall:
bash build/5.0/remove-issabel-callcenter.sh

# Service management
systemctl start issabeldialer
systemctl status issabeldialer
systemctl stop issabeldialer
```

Post-installation notes
----


**1. The agent Hold feature has its own parking lot — nothing to configure.** Putting
a call on hold parks it, but not in the PBX "default" lot: the installer writes a
dedicated `callcenter_hold` lot into `/etc/asterisk/res_parking_custom_general.conf`,
with a 900-second `parkingtime` (the maximum hold time) and 100 slots
(`70001-70100`, the cap on concurrent holds system-wide). The PBX Parking screen in
the GUI configures the *default* lot only and no longer affects agent hold.

To change the hold timeout or the number of slots, edit `parkingtime` / `parkpos`
inside the `; BEGIN ISSABEL CALL-CENTER PARKING LOT` block of that file and run
`asterisk -rx "module reload res_parking"`. Keep `parkpos` clear of the default
lot's range — Asterisk refuses overlapping parking extensions. If you raise
`parkingtime`, also raise the three `Wait(900)` calls in the call center block of
`/etc/asterisk/extensions_custom.conf` to match; they cap how long the agent side
waits during a hold taken around an attended transfer.

**2. The lot deliberately has no `courtesytone`,** so neither the agent nor the
customer hears a beep when a held call is resumed. This matches the behaviour of a
hold taken after a cancelled attended transfer, which is resumed by bridging and was
always silent. Agent-type (app_agent_pool) logins still hear the `custom_beep` from
`agents.conf` when a call is offered to them, including on resume.

License
----

GPLv2 or Later

>This program is free software; you can redistribute it and/or
>modify it under the terms of the GNU General Public License
>as published by the Free Software Foundation; either version 2
>of the License, or (at your option) any later version.

>This program is distributed in the hope that it will be useful,
>but WITHOUT ANY WARRANTY; without even the implied warranty of
>MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
>GNU General Public License for more details.

>You should have received a copy of the GNU General Public License
>along with this program; if not, write to the Free Software
>Foundation, Inc., 51 Franklin Street, Fifth Floor, Bosto



