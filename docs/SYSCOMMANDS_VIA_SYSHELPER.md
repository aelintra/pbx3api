# Syscommands via syshelper (no sudoers)

**Goal:** Run Start PBX, Stop PBX, and Reboot through the existing pbx3 privileged worker (syshelper on port 7601) instead of sudo from the API. Avoids broad sudoers permissions for www-data.

**TODO (separate):** Rewrite syshelper.pl daemon (modern language, same protocol, keep runit).

---

## Protocol (syshelper.pl)

- **Listen:** 127.0.0.1:7601
- **On connect:** server sends `Ready\n`
- **Client sends:** one line = command (e.g. `/bin/systemctl start asterisk`) — **no sudo**
- **Server:** runs command via shell, sends stdout/stderr then `<<EOT>>\n`
- **Client:** read until line contains `<<EOT>>`, treat everything before that as response

Daemon runs as privileged user (runit); no password, no sudo in PHP.

---

## Plan (pbx3api)

1. **Add a syscmd client helper** (e.g. in `app/Helpers/` or private method in `SysCommandController`):
   - Connect to host/port (env: `PBX3_SYSCMD_HOST=127.0.0.1`, `PBX3_SYSCMD_PORT=7601`, defaults 127.0.0.1 / 7601).
   - Read one line (expect "Ready").
   - Send `"{command}\n"`.
   - Read lines until a line equals or contains `<<EOT>>`; concatenate preceding lines as response.
   - On connection refused / timeout: return error (no response).
   - Optional: short timeout (e.g. 5s) so API doesn’t hang if daemon is down.

2. **Use it in SysCommandController** for `start`, `stop`, `reboot`:
   - **start:** send `/bin/systemctl start asterisk` (after existing “already running” check via `ps`).
   - **stop:** send `/bin/systemctl stop asterisk` (after “not running” check).
   - **reboot:** send `/sbin/reboot`.
   - If syscmd client fails (e.g. connection refused): return 502 with message/detail (e.g. “syshelper not reachable”).
   - If client succeeds: return 200 with existing success message (daemon doesn’t send exit code; treat “got reply” as success, or inspect response for known errors if needed).

3. **No sudo in API:** Remove `exec('sudo ...')` for these three actions. No sudoers entries required for www-data for systemctl/reboot.

4. **Optional fallback:** Env flag e.g. `PBX3_USE_SYSCMD=true` (default true); if false, keep current sudo path for environments where syshelper isn’t running (document that sudoers would then be required).

5. **Commit / Asterisk version:** Already use sudo for `asterisk -rx` in some places (e.g. getAsteriskRelease). Those can stay as-is for now, or later be routed through syshelper with commands like `sudo /usr/sbin/asterisk -rx 'core show version'` if the daemon runs as root and can run sudo, or the daemon runs as a user that can hit the Asterisk socket — TBD when rewriting the daemon.

---

## Summary

| Action   | Command to send to syshelper     | Current (API)        |
|----------|-----------------------------------|----------------------|
| Start PBX| `/bin/systemctl start asterisk`   | sudo systemctl start |
| Stop PBX | `/bin/systemctl stop asterisk`   | sudo systemctl stop  |
| Reboot   | `/sbin/reboot`                   | sudo /sbin/reboot    |

One small client helper, three controller methods switched to it, optional env for host/port and use-syshelper flag. No sudoers for www-data.
