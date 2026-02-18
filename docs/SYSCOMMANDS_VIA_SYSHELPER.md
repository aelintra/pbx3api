# Syscommands via syshelper (no sudoers)

**Goal:** Run privileged commands through the existing pbx3 worker (syshelper on port 7601) instead of sudo from the API. Avoids broad sudoers permissions for www-data.

**Pattern (for all new work):** Any new requirement that needs to run a privileged command (systemctl, reboot, asterisk -rx, shorewall, etc.) **must** use the syshelper daemon via `requestSyscmd()` in `SysCommandController` (or a shared helper). Do not add new sudo calls in the API.

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

2. **Use it in SysCommandController** for `start`, `stop`, `reboot`, and for **Asterisk version** (getAsteriskRelease):
   - **start:** send `/bin/systemctl start asterisk` (after “already running” check via `ps`).
   - **stop:** send `/bin/systemctl stop asterisk` (after “not running” check).
   - **reboot:** send `/sbin/reboot`.
   - **Asterisk version (sysnotes):** send `{PBX3_ASTERISK_EXEC} -rx 'core show version' 2>/dev/null` via requestSyscmd; parse major.minor.patch from response.
   - On failure: return 502 with message/detail (e.g. “syshelper not reachable”). On success: 200 (or null for version if daemon unreachable).

3. **No sudo in API** for these actions. No sudoers entries required for www-data for systemctl, reboot, or asterisk -rx.

4. **Optional fallback:** Env flag e.g. `PBX3_USE_SYSCMD=true` (default true); if false, keep current sudo path (document that sudoers would then be required).

---

## Summary (implemented)

| Action          | Command to send to syshelper                    | Status   |
|-----------------|-------------------------------------------------|----------|
| Start PBX       | `/bin/systemctl start asterisk`                 | via daemon |
| Stop PBX        | `/bin/systemctl stop asterisk`                  | via daemon |
| Reboot          | `/sbin/reboot`                                  | via daemon |
| Asterisk version| `{asterisk} -rx 'core show version' 2>/dev/null`| via daemon |

**Next candidates (single panel):** Firewall (shorewall / shorewall6 check & restart) — see SUDO_AND_PRIVILEGED_COMMANDS.md.

**Later (pushed to end):** Restore flows (LDAP, Asterisk config/sounds/voicemail in Helper.php).
