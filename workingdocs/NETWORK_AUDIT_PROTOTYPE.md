# Network / IP Settings (sarknetwork) audit – panel port

**Purpose:** Define data sources, API, and SPA design for the sarknetwork panel port. This panel is **not single-table**: it combines **globals** (instance) columns with **read-only system/runtime** data (hostname, detected IP, public IP, MAC). Single-screen at `/ip-settings` (or `/network`).

**SARK reference:** `sail65/sail-6/opt/sark/php/sarknetwork/view.php` – inspect for full list of fields and actions (save, any special behaviour). This doc is based on pbx3 schema and existing API.

**Schema source:** `pbx3/pbx3-1/opt/pbx3/db/db_sql/sqlite_create_instance.sql` (table `globals`). Runtime data from system (ip addr, hostname, curl ifconfig.me) and pbx3 setip.php / NetHelperClass.

---

## 1. Data sources (multi-source panel)

### 1.1 Editable: `globals` (instance, single row)

Network-relevant columns in **globals** (source of truth: sqlite_create_instance.sql):

| Column       | Type   | Role / notes |
|-------------|--------|---------------|
| bindaddr    | TEXT   | SIP bind address (nullable). |
| bindport    | TEXT   | SIP bind port (default 5060). |
| staticipv4  | TEXT   | Static IPv4 for VoIP; when set, NetHelper/get_localIPV4 returns this. setip.php can add/remove alias. |
| fqdn        | TEXT   | Public FQDN (not in IP Settings panel; moving elsewhere in new system). |
| fqdninspect | TEXT   | FQDN inspect (not in IP Settings panel; moving elsewhere). |
| fqdnprov    | TEXT   | FQDN provisioning (not in IP Settings panel; moving elsewhere). |
| edomain     | TEXT   | Override public IP / external domain (often read-only or system-maintained). |
| sendedomain | TEXT   | Send domain in SIP (not in IP Settings panel; stays in System Globals). |
| localip     | TEXT   | Local IP (system/maintained; set by setip or NetHelper). |
| natdefault  | TEXT   | NAT default: local | remote. |
| natparams   | TEXT   | NAT params (e.g. force_rport,comedia). |
| sitename    | TEXT   | Site name. |
| tlsport     | INTEGER| TLS port (default 5061). |

**Scope decision:** The IP Settings panel does **not** include **fqdn**, **fqdninspect**, or **fqdnprov**; those will move elsewhere in the new system. This panel only edits: bindaddr, bindport, staticipv4, tlsport, natdefault, natparams, sitename, plus read-only system info from sysnotes.

**Current API:** GET/PUT **sysglobals**. Model **hides** from response: `pkey`, `fqdninspect`, `fqdnprov`, `mycommit`, `staticipv4`, `userotp`, `vcl`. For IP Settings we only need to **expose staticipv4** (remove from `$hidden`); fqdninspect and fqdnprov remain hidden and are not edited in this panel.

### 1.2 Read-only (runtime / system)

- **hostname** – `gethostname()`
- **local_ip** – from globals.staticipv4 if set, else first inet on first UP interface (`ip addr`)
- **public_ip** – from `curl -s ifconfig.me` (or similar)
- **mac** – MAC of default-route interface

Already returned by **GET syscommands/sysnotes** as `network`: `{ hostname, local_ip, mac, public_ip }`. No separate network endpoint today; the panel can call GET sysglobals + GET syscommands/sysnotes and merge.

### 1.3 Not in API (setip.php, Shorewall, Asterisk)

- **setip.php** (pbx3): Runs at install; reads globals.staticipv4, detects interface and CIDR from `ip addr` / `ip route`; writes `/etc/shorewall/local.lan`, `/etc/shorewall/local.if1`, Asterisk localnet, `/etc/issue`. No API for “run setip” or “interface name / netmask” in this audit; optional later.
- Interface name, network/CIDR, netmask: available in pbx3 NetHelperClass from `ip addr` / `ip route`; not currently exposed in pbx3api. Can be added to a dedicated GET network or to sysnotes if needed for display-only.

---

## 2. Current API gaps

| Item | Current state | Recommendation |
|------|----------------|----------------|
| staticipv4 in GET | Hidden in Sysglobal model | Expose for IP Settings (remove from `$hidden`), or add a “network” scope that returns it only for this panel. |
| fqdninspect, fqdnprov | Hidden in Sysglobal model | Expose if the panel should show/edit FQDN inspection and “provision with FQDN”. |
| Single payload for panel | Two calls (sysglobals + sysnotes) | Panel uses GET sysglobals + GET sysnotes; optional later: GET /network. |
| localip, edomain | Read-only in SysglobalsEditView | Keep read-only in IP Settings (system/maintained). |

---

## 3. Recommended approach

**Option A (minimal):** Expose **staticipv4** (and optionally **fqdninspect**, **fqdnprov**) in Sysglobal: remove from `$hidden`. No new endpoint. IP Settings panel: single-screen view that (1) GET sysglobals, (2) GET syscommands/sysnotes, (3) displays only network-related fields in sections (Binding, FQDN & domain, NAT, Read-only system info); (4) Save → PUT sysglobals with the same updateable columns (including staticipv4). SysglobalsEditView already has a “Network” section but does not show staticipv4 (hidden); after exposing, both panels can show it; IP Settings view is a focused slice of the same data.

**Option B (dedicated endpoint):** Add GET **/network** returning `{ ...networkRelevantGlobals, hostname, local_ip, public_ip, mac }` and optionally interface/CIDR if we add that from syshelper. PUT still to sysglobals for persistence. Cleaner for the panel (one load) and keeps “network” as a logical resource.

**Recommendation:** Start with **Option A** (expose staticipv4, fqdninspect, fqdnprov in Sysglobal; build IP Settings view that uses GET sysglobals + GET sysnotes). If we later want one-call load and more read-only system fields, add GET /network (Option B).

---

## 4. Fields for the IP Settings single-screen

**Editable (from globals via PUT sysglobals):**

- Bind Port (bindport) — original panel exposes this; bindaddr is not exposed in sail65
- Static IPv4 (staticipv4) – for VoIP; when set, used as “local” IP for SIP/Asterisk
- TLS port (tlsport)
- NAT default (natdefault) – local | remote
- NAT params (natparams)
- Site name (sitename)

**Read-only (from GET syscommands/sysnotes → network):** hostname, local_ip, public_ip, mac.

**Not in this panel:** fqdn, fqdninspect, fqdnprov, sendedomain, edomain, localip (moving or remain in System Globals).

**Sections:** (Original sail65 sarknetwork exposes bindport but **not** bindaddr — no displayInputFor('bindaddr') in view.php; bindaddr is loaded from globals but not shown/edited. IP Settings panel matches: no Bind Address field.)

1. **Binding** – bindport, tlsport, staticipv4 (no bindaddr; not in original panel)
2. **NAT** – natdefault, natparams
3. **Site** – sitename
4. **System (read-only)** – hostname, local_ip, public_ip, mac (from sysnotes)

---

## 5. Implementation checklist

### API (pbx3api)

- [ ] **Sysglobal model:** Remove `staticipv4` from `$hidden` so GET sysglobals returns it for IP Settings. Do **not** expose fqdninspect/fqdnprov (they move elsewhere).
- [ ] **SysglobalController:** updateableColumns already include staticipv4; no change.

### SPA (pbx3spa)

- [ ] **Route:** e.g. `/ip-settings` (or `/network`).
- [ ] **View:** e.g. `NetworkView.vue` or `IpSettingsView.vue` – single-screen, no list/create/detail. Load GET sysglobals + GET syscommands/sysnotes; display sections as in §4; Save → PUT sysglobals with edited fields. Use FormField, FormSegmentedPill for YES/NO, FormReadonly for hostname, local_ip, public_ip, mac, localip, edomain.
- [ ] **Nav:** Add “IP Settings” (or “Network”) under System in sidebar.
- [ ] **Docs:** Update SINGLE_PANEL_SCREENS.md (IP Settings ✅), SAIL65_PANEL_PORT_PLAN.md when done.

### pbx3 (backend)

- setip.php and NetHelperClass already use globals.staticipv4; no change required for this panel. If we later allow “apply setip” from the panel (e.g. after changing staticipv4), that would be a separate syshelper call.

---

## 6. Sail65 view.php (to confirm)

**Action for implementer:** Open `sail65/sail-6/opt/sark/php/sarknetwork/view.php` and list any additional fields or actions (e.g. SSH port, SMTP, ICMP toggles) that are not yet in globals or sysnotes. Map those to either (a) new globals columns, (b) read-only from system, or (c) deferred. This audit assumes the core set above; SARK may have more UI items that need a product decision.
