# API routes: Data vs Operational

The API provides **CRUD on data objects** (configuration/entities in PBX3) and **operational** endpoints: **action commands** (housekeeping, control) and **live/system-state retrieval** (runtime info from Asterisk or the system).

---

## Data routes (CRUD on objects)

These operate on stored configuration/entities: list, show, create, update, delete.

| Resource | Methods | Notes |
|----------|---------|--------|
| **auth/users** | GET index, GET {id}, GET mail/{email}, GET name/{name}, GET endpoint/{endpoint}, POST register, DELETE revoke/{id}, DELETE {id} | User/API-user records (admin). |
| **agents** | GET, GET {agent}, POST, PUT {agent}, DELETE {agent} | Queue-agent objects. |
| **backups** | GET (list), GET {backup} (download), POST (upload), DELETE {backup} | Backup set: list, download file, upload, delete from set. |
| **coscloses** | GET, GET {cosclose}, POST, PUT {cosclose}, DELETE {cosclose} | Class-of-service closed instances. |
| **cosopens** | GET, GET {cosopen}, POST, PUT {cosopen}, DELETE {cosopen} | Class-of-service open instances. |
| **cosrules** | GET, GET {classofservice}, POST, PUT {classofservice}, DELETE {classofservice} | Class-of-service rules. |
| **customapps** | GET, GET {id}, POST, PUT {id}, DELETE {id} | Custom app definitions. |
| **daytimers** | GET, GET {daytimer}, POST, PUT {daytimer}, DELETE {daytimer} | Recurring day timers. |
| **destinations** | GET | Read-only list of valid destinations. |
| **extensions** | GET, GET {extension}, POST mailbox/provisioned/vxt/unprovisioned/webrtc, PUT {extension}, PUT {extension}/runtime, DELETE {extension} | Extensions (create variants by type). *Exception:* GET {extension}/**runtime** is operational (live state). |
| **firewalls** | GET ipv4, GET ipv6, POST ipv4, POST ipv6 | **Data:** GET returns the list of active firewall rules; POST sends a rules array to add, change, or delete rules (update the ruleset). *Exception:* PUT ipv4/ipv6 (restart) is operational. |
| **greetings** | GET (list), GET {greeting} (download), POST, DELETE {greeting} | Greeting files. |
| **holidaytimers** | GET, GET {holidaytimer}, POST, PUT {holidaytimer}, DELETE {holidaytimer} | Holiday (non-recurring) timers. |
| **inboundroutes** | GET, GET {inboundroute}, POST, PUT {inboundroute}, DELETE {inboundroute} | Inbound routes (DDI/DiD). |
| **ivrs** | GET, GET {ivr}, POST, PUT {ivr}, DELETE {ivr} | IVR menus. |
| **logs** | GET (index), GET cdrs{limit} | Log index and CDR download (stored log data). |
| **queues** | GET, GET {queue}, POST, PUT {queue}, DELETE {queue} | Call queues. |
| **routes** | GET, GET {route}, POST, PUT {route}, DELETE {route} | Ring groups / routes. |
| **snapshots** | GET (list), GET {snapshot} (download), POST (upload), DELETE {snapshot} | Snapshot set: list, download, upload, delete. *Exception:* GET new, PUT {snapshot} (restore) are operational. |
| **sysglobals** | GET, PUT | System-wide global settings (key/value config). |
| **tenants** | GET, GET {tenant}, POST, PUT {tenant}, DELETE {tenant} | Tenants (clusters). |
| **trunks** | GET, GET {trunk}, POST, PUT {trunk}, DELETE {trunk} | Trunks. |

---

## Operational routes (actions and live state)

These perform **housekeeping/control actions** or return **live/system state** (not just stored CRUD).

### Auth (session/token)

| Route | Method | Purpose |
|-------|--------|---------|
| auth/login | POST | Obtain Bearer token (no prior auth). |
| auth/logout | GET | Destroy current token. |
| auth/whoami | GET | Current user info. |

### Backups and snapshots (actions)

| Route | Method | Purpose |
|-------|--------|---------|
| backups/new | GET | Request a new backup be taken and added to the set. |
| backups/{backup} | PUT | Restore from backup (choose what to restore in body). |
| snapshots/new | GET | Request a new snapshot be taken. |
| snapshots/{snapshot} | PUT | Restore from snapshot. |

### Firewall (control)

| Route | Method | Purpose |
|-------|--------|---------|
| firewalls/ipv4 | PUT | Restart IPv4 firewall (apply/reload the ruleset). |
| firewalls/ipv6 | PUT | Restart IPv6 firewall (apply/reload the ruleset). |

*Data side:* GET returns the list of active rules; POST updates the ruleset (add, change, delete rules via the submitted rules array). Only PUT is an action (restart).

### Extensions (live state)

| Route | Method | Purpose |
|-------|--------|---------|
| extensions/{extension}/runtime | GET | Runtime info from PBX (e.g. CFIM, CFBS, ringdelay). |

### System commands (housekeeping and state)

| Route | Method | Purpose |
|-------|--------|---------|
| syscommands | GET | List available system commands. |
| syscommands/commit | GET | Commit config (housekeeping). |
| syscommands/reboot | GET | Reboot system. |
| syscommands/pbxrunstate | GET | Retrieve PBX run state. |
| syscommands/start | GET | Start PBX. |
| syscommands/stop | GET | Stop PBX. |

### Asterisk AMI (live state and control)

All **astamis/** endpoints are operational: they query or command the running Asterisk (Manager Interface) or AstDB.

| Route | Method | Purpose |
|-------|--------|---------|
| astamis | GET | List/catalog of AMI actions. |
| astamis/CoreSettings | GET | Core settings (live). |
| astamis/CoreStatus | GET | Core status (live). |
| astamis/ExtensionState/{id}{context?} | GET | Extension state (live). |
| astamis/MailboxCount/{id} | GET | Mailbox count (live). |
| astamis/MailboxStatus/{id} | GET | Mailbox status (live). |
| astamis/QueueStatus/{id} | GET | Queue status (live). |
| astamis/QueueSummary/{id} | GET | Queue summary (live). |
| astamis/Reload | GET | Reload Asterisk. |
| astamis/originate | POST | Originate a call (action). |
| astamis/DBget/{id}/{key} | GET | Asterisk DB get. |
| astamis/DBput/{id}/{key}/{value} | PUT | Asterisk DB put. |
| astamis/DBdel/{id}/{key} | DELETE | Asterisk DB delete. |
| astamis/Hangup/{id}/{key} | DELETE | Soft hangup channel (action). |
| astamis/{action}/{id?} | GET | Generic AMI list (Status, SIPpeers, CoreShowChannels, etc.). |

### Non-admin AMI (srktwin)

| Route | Method | Purpose |
|-------|--------|---------|
| astamis/DBput/srktwin/{key}/{value} | PUT | AstDB put (cluster-scoped; no admin). |
| astamis/DBdel/srktwin/{key} | DELETE | AstDB del (cluster-scoped; no admin). |

---

## Summary

- **Data:** CRUD (and read-only lists) on agents, backups (list/download/upload/delete), coscloses, cosopens, cosrules, customapps, daytimers, destinations, extensions (except runtime GET), **firewall rules (GET = list active rules, POST = add/change/delete rules via rules array)**, greetings, holidaytimers, inboundroutes, ivrs, logs, queues, routes, snapshots (list/download/upload/delete), sysglobals, tenants, trunks, auth/users.
- **Operational:** auth login/logout/whoami; backups/new and PUT restore; snapshots/new and PUT restore; firewall PUT restart; extensions/{id}/runtime GET; syscommands (list, commit, reboot, pbxrunstate, start, stop); all astamis/* (live state + Reload, originate, DB get/put/del, Hangup, AMI lists).
