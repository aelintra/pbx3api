# Sudo and privileged commands in the API

List of all shell/exec/sudo-style commands in pbx3api.

**Pattern for new work:** Any new privileged command **must** use the syshelper daemon (see `docs/SYSCOMMANDS_VIA_SYSHELPER.md`). Use `requestSyscmd()` in SysCommandController or a shared helper; do not add new sudo in the API.

**Already via syshelper (no sudo):** Start PBX, Stop PBX, Reboot, Asterisk version (sysnotes).

---

## Next single-panel candidates: explicit `sudo` (move to syshelper)

| File | Line | Command | Purpose |
|------|------|---------|---------|
| **FirewallController** | 128, 131 | `sudo /sbin/shorewall check`, `sudo /sbin/shorewall restart` | Firewall (IPv4) |
| **FirewallController** | 146, 149 | `sudo /sbin/shorewall6 check`, `sudo /sbin/shorewall6 restart` | Firewall (IPv6) |

---

## Later (pushed to end): explicit `sudo` in restore flows

| File | Line | Command | Purpose |
|------|------|---------|---------|
| **Helper.php** | 212 | `sudo /bin/rm -rf /etc/asterisk/*` | Restore: clear Asterisk config |
| **Helper.php** | 264–268 | `sudo /etc/init.d/slapd stop`, `sudo /bin/rm -rf /var/lib/ldap/*`, `sudo slapadd`, `sudo chown openldap`, `sudo slapd start` | Restore: LDAP |

---

## No `sudo` but privileged paths (may need root/sudoers or syshelper)

These use `exec` / `shell_exec` / backticks and touch system dirs or run as www-data; behavior depends on file ownership and sudoers.

### SysCommandController

| Line | Command | Purpose |
|------|---------|---------|
| 58 | `exec('/bin/sh genAst.sh')` | Commit: run config generator |
| 63 | `exec('asterisk -rx core reload')` | Commit: Asterisk reload |
| 91, 108, 124 | `` `ps \| grep asterisk` `` | Run-state checks (read-only) |
| 197 | `shell_exec("dpkg-query -W ... pbx3")` | App version (read-only) |
| 215 | `` `free -b` `` | RAM (read-only) |
| 221 | `` `df -k .` `` | Disk (read-only) |
| 227 | `` `asterisk -rx 'database get STAT OCSTAT'` `` | Master timer (Asterisk socket) |
| 243 | `` `ps \| grep asterisk` `` | Run state for sysnotes |
| 263 | `shell_exec($cmd)` | safeShell() – generic (used for distro, MAC, etc.) |
| 308, 313 | `shell_exec("ip addr ...")` | Local IP (read-only) |

### ExtensionController

| Line | Command | Purpose |
|------|---------|---------|
| 749 | `` `grep ... manuf` `` | MAC vendor lookup (read file) |

### Helper.php (backup / restore / snapshot flows)

| Line(s) | Command | Purpose |
|---------|---------|---------|
| 119 | `shell_exec('/usr/sbin/slapcat > /tmp/...')` | Backup: LDAP dump |
| 125, 131–133 | zip, mv, chown, chmod | Backup: create and place in /opt/pbx3/bkup |
| 152–154 | cp, chown, chmod | Snapshot: copy DB to /opt/pbx3/snap |
| 179, 181 | mkdir, unzip | Restore: unpack |
| 193–197 | cp, chown, reloader script | Restore: DB + reloader |
| 212–215 | rm /etc/asterisk, cp, chown, chmod | Restore: Asterisk config (212 is sudo) |
| 229–232 | rm, cp, chown, chmod | Restore: usergreeting sounds |
| 247–250 | rm, cp, chown, chmod | Restore: voicemail |
| 264–268 | sudo slapd / ldap (see above) | Restore: LDAP |
| 280, 283 | rm, srkreload script | Restore: cleanup + reload |
| 298, 394 | `` `ps \| grep asterisk` `` | Run-state checks |

### SnapShotController

| Line | Command | Purpose |
|------|---------|---------|
| 93 | `shell_exec("/bin/mv ... /opt/pbx3/snap")` | Move snapshot into snap dir |
| 117–119 | cp, chown, chmod | Restore snapshot to pbx3.db |
| 137 | `shell_exec("/bin/rm -r .../snap/$snapshot")` | Delete snapshot file |

### LogController

| Line | Command | Purpose |
|------|---------|---------|
| 48 | `shell_exec("$cmd /var/log/asterisk/...")` | Export CDR log (read/copy) |

### GreetingController

| Line | Command | Purpose |
|------|---------|---------|
| 82–84 | mv, chown asterisk, chmod | Upload greeting to /usr/share/asterisk/sounds |
| 104 | rm | Delete greeting file |

### FirewallController

| Line | Command | Purpose |
|------|---------|---------|
| 69 | `shell_exec("/bin/mv ... /etc/shorewall/pbx3_rules")` | Install IPv4 rules file |
| 94 | `shell_exec("/bin/mv ... /etc/shorewall6/pbx3_rules6")` | Install IPv6 rules file |
| 128, 131, 146, 149 | sudo shorewall / shorewall6 (see above) | Check and restart firewall |

### BackupController

| Line | Command | Purpose |
|------|---------|---------|
| 93 | `shell_exec("/bin/mv ... /opt/pbx3/bkup")` | Move backup into bkup dir |
| 159 | `shell_exec("/bin/rm -r .../bkup/$backup")` | Delete backup file |

---

## Summary

- **Via syshelper (no sudo):** Start PBX, Stop PBX, Reboot, Asterisk version (sysnotes).
- **Next candidates (single panel):** Firewall (shorewall / shorewall6 check & restart) — move to syshelper.
- **Later (end):** Restore flows in Helper.php (Asterisk config clear, LDAP stop/rm/slapadd/chown/start).
- **Privileged paths (no sudo):** Commit (genAst, asterisk reload), backup/restore/snapshot, greetings, firewall mv to /etc/shorewall*. May need syshelper depending on deployment.
