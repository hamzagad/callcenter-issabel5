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
# To Uninstall:
bash build/5.0/remove-issabel-callcenter.sh

# Service management
systemctl start issabeldialer
systemctl status issabeldialer
systemctl stop issabeldialer
```

Post-installation notes
----


**1. Increase the PBX Park timeout — the agent Hold feature uses Park.** Putting a
call on hold parks it, so `parkingtime` is effectively the maximum hold time: when
it expires the caller is returned automatically, with no warning to the agent.
Raise it to at least 1800 seconds in the GUI under
*PBX → PBX Configuration → Applications → Parking → Default Lot → "Parking Timeout (seconds)"*, then click
Apply Changes. Do not hand-edit the Asterisk 11 configuration files — it is regenerated from the database.

**2. Check the number of parking slots — it caps concurrent holds.** The `parkpos`
range of the parking lot (default `701-708`, i.e. 8 slots) limits how many calls
can be on hold at once across the whole system, regardless of agent count. Widen it
in the same Parking Lot screen to cover the number of concurrent agents you expect.

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



