#!/usr/bin/env bash
# Hit every GET endpoint on the PBX3 API and report status.
# Usage: ./fetch-all-endpoints.sh [base_url] [bearer_token]
# Defaults: base_url=https://192.168.1.205:44300/api  token=1|QJIK...

set -e
BASE="${1:-https://192.168.1.205:44300/api}"
TOKEN="${2:-1|QJIKdoUvZXIulwp5pEaPoRwdRJIKU116lzZhzYyoc968185b}"
run() {
  local method="$1"
  local path="$2"
  local url="${BASE}/${path}"
  local code
  code=$(curl -s -k -w '%{http_code}' -o /tmp/pbx3_resp.json \
    -H "Accept: application/json" \
    -H "Authorization: Bearer $TOKEN" \
    -X "$method" \
    "$url" 2>/dev/null || echo "000")
  if [[ "$code" == "200" ]]; then
    echo "OK   $method $path -> $code"
  else
    echo "FAIL $method $path -> $code"
    head -c 200 /tmp/pbx3_resp.json 2>/dev/null | tr '\n' ' '; echo
  fi
}

# Helper: get first pkey or id from last response (assumes array). Works without jq.
first_id() {
  if command -v jq &>/dev/null; then
    jq -r 'if type == "array" then (.[0].pkey // .[0].id // .[0] // empty) else (.pkey // .id // empty) end' /tmp/pbx3_resp.json 2>/dev/null || true
  else
    python3 -c "
import sys, json
try:
    d = json.load(open('/tmp/pbx3_resp.json'))
    if isinstance(d, list) and d:
        print(d[0].get('pkey') or d[0].get('id') or '')
    elif isinstance(d, dict):
        print(d.get('pkey') or d.get('id') or '')
except: pass
" 2>/dev/null || true
  fi
}

echo "=== PBX3 API GET endpoint check (base=$BASE) ==="
echo ""

# --- Auth (no path param) ---
run GET "auth/whoami"

# --- Index endpoints (no path param) ---
run GET "agents"
run GET "astamis"
run GET "astamis/CoreSettings"
run GET "astamis/CoreStatus"
run GET "backups"
run GET "backups/new"
run GET "coscloses"
run GET "cosopens"
run GET "cosrules"
run GET "customapps"
run GET "daytimers"
run GET "destinations"
run GET "extensions"
run GET "firewalls/ipv4"
run GET "firewalls/ipv6"
run GET "greetings"
run GET "holidaytimers"
run GET "inboundroutes"
run GET "ivrs"
run GET "logs"
run GET "logs/cdrs10"
run GET "queues"
run GET "snapshots"
run GET "snapshots/new"
run GET "routes"
run GET "syscommands"
run GET "syscommands/commit"
run GET "syscommands/reboot"
run GET "syscommands/pbxrunstate"
run GET "syscommands/start"
run GET "syscommands/stop"
run GET "sysglobals"
run GET "tenants"
run GET "trunks"

# --- Auth users (index) ---
run GET "auth/users"

# --- Show by ID: need to get IDs from index first ---
run GET "tenants"
TENANT_ID=$(first_id)
if [[ -n "$TENANT_ID" ]]; then run GET "tenants/$TENANT_ID"; fi

run GET "agents"
AGENT_ID=$(first_id)
if [[ -n "$AGENT_ID" ]]; then run GET "agents/$AGENT_ID"; fi

run GET "extensions"
EXT_ID=$(first_id)
if [[ -n "$EXT_ID" ]]; then
  run GET "extensions/$EXT_ID"
  run GET "extensions/$EXT_ID/runtime"
fi

run GET "coscloses"
COSCLOSE_ID=$(first_id)
if [[ -n "$COSCLOSE_ID" ]]; then run GET "coscloses/$COSCLOSE_ID"; fi

run GET "cosopens"
COSOPEN_ID=$(first_id)
if [[ -n "$COSOPEN_ID" ]]; then run GET "cosopens/$COSOPEN_ID"; fi

run GET "cosrules"
COSRULE_ID=$(first_id)
if [[ -n "$COSRULE_ID" ]]; then run GET "cosrules/$COSRULE_ID"; fi

run GET "customapps"
CUSTOMAPP_ID=$(first_id)
if [[ -n "$CUSTOMAPP_ID" ]]; then run GET "customapps/$CUSTOMAPP_ID"; fi

run GET "daytimers"
DAYTIMER_ID=$(first_id)
if [[ -n "$DAYTIMER_ID" ]]; then run GET "daytimers/$DAYTIMER_ID"; fi

run GET "greetings"
GREETING_ID=$(first_id)
if [[ -n "$GREETING_ID" ]]; then run GET "greetings/$GREETING_ID"; fi

run GET "holidaytimers"
HT_ID=$(first_id)
if [[ -n "$HT_ID" ]]; then run GET "holidaytimers/$HT_ID"; fi

run GET "inboundroutes"
INBOUND_ID=$(first_id)
if [[ -n "$INBOUND_ID" ]]; then run GET "inboundroutes/$INBOUND_ID"; fi

run GET "ivrs"
IVR_ID=$(first_id)
if [[ -n "$IVR_ID" ]]; then run GET "ivrs/$IVR_ID"; fi

run GET "queues"
QUEUE_ID=$(first_id)
if [[ -n "$QUEUE_ID" ]]; then run GET "queues/$QUEUE_ID"; fi

run GET "backups"
BACKUP_ID=$(first_id)
if [[ -n "$BACKUP_ID" ]]; then run GET "backups/$BACKUP_ID"; fi

run GET "snapshots"
SNAP_ID=$(first_id)
if [[ -n "$SNAP_ID" ]]; then run GET "snapshots/$SNAP_ID"; fi

run GET "routes"
ROUTE_ID=$(first_id)
if [[ -n "$ROUTE_ID" ]]; then run GET "routes/$ROUTE_ID"; fi

run GET "trunks"
TRUNK_ID=$(first_id)
if [[ -n "$TRUNK_ID" ]]; then run GET "trunks/$TRUNK_ID"; fi

# --- AMI instance endpoints (need id) ---
if [[ -n "$EXT_ID" ]]; then
  run GET "astamis/ExtensionState/$EXT_ID"
  run GET "astamis/MailboxCount/$EXT_ID"
  run GET "astamis/MailboxStatus/$EXT_ID"
fi
if [[ -n "$QUEUE_ID" ]]; then
  run GET "astamis/QueueStatus/$QUEUE_ID"
  run GET "astamis/QueueSummary/$QUEUE_ID"
fi
run GET "astamis/Reload"

# --- AMI list endpoints (action + optional id) ---
run GET "astamis/Status"
run GET "astamis/SIPpeers"
run GET "astamis/IAXpeers"
run GET "astamis/CoreShowChannels"
run GET "astamis/DeviceStateList"
run GET "astamis/ExtensionStateList"
run GET "astamis/VoicemailUsersList"

echo ""
echo "=== Done ==="
