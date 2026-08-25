<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Home firewall — UFW declarative allow-list (Phase 3).
 * HoR: /etc/pbx3/firewall.allows.json
 * Spec: pbx3/workingdocs/UFW_SHOREWALL_MIGRATION.md §7.
 */
class FirewallController extends Controller
{
    private const ALLOWS_FILE = '/etc/pbx3/firewall.allows.json';

    private const PROTO_OK = ['tcp', 'udp', 'icmp', 'all'];

    /**
     * GET firewalls — structured allow list (unified IPv4/IPv6 under UFW).
     */
    public function index()
    {
        $data = $this->readAllows();
        if ($data === null) {
            return response()->json([
                'message' => 'Firewall allow-list not found. Run ufw-apply-baseline.sh fleet|solo once.',
                'profile' => null,
                'rules' => [],
            ], 404);
        }

        return response()->json($data, 200);
    }

    /** Compat: GET firewalls/ipv4 → same as index. */
    public function ipv4()
    {
        return $this->index();
    }

    /**
     * Compat: GET firewalls/ipv6 — UFW is dual-stack; no separate v6 file.
     */
    public function ipv6()
    {
        return response()->json([
            'profile' => null,
            'rules' => [],
            'message' => 'IPv6 uses the same UFW allow-list as IPv4 (see GET firewalls).',
            'deprecated' => true,
        ], 200);
    }

    /**
     * POST firewalls — validate + write allow-list (does not apply).
     * Body: { "profile"?: "fleet"|"solo", "rules": [ { action, proto, port, from, comment }, ... ] }
     */
    public function save(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'profile' => 'nullable|in:fleet,solo',
            'rules' => 'required|array',
            'rules.*.action' => 'required|in:allow',
            'rules.*.proto' => 'required|in:tcp,udp,icmp,all',
            'rules.*.port' => 'nullable|string|max:64',
            'rules.*.from' => 'required|string|max:64',
            'rules.*.comment' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $rules = [];
        foreach ($request->input('rules') as $r) {
            $proto = strtolower($r['proto']);
            $port = isset($r['port']) ? trim((string) $r['port']) : '';
            $from = trim((string) $r['from']);
            $comment = isset($r['comment']) ? trim((string) $r['comment']) : '';

            if ($err = $this->validateRuleShape($proto, $port, $from)) {
                return response()->json(['rules' => [$err]], 422);
            }

            $rules[] = [
                'action' => 'allow',
                'proto' => $proto,
                'port' => ($proto === 'icmp') ? '' : $port,
                'from' => $from === '' ? 'any' : $from,
                'comment' => $comment,
            ];
        }

        $existing = $this->readAllows();
        $profile = $request->input('profile')
            ?: ($existing['profile'] ?? 'fleet');

        $payload = [
            'profile' => $profile,
            'rules' => $rules,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $tmp = 'firewall_allows_' . time() . '.json';
        $tmpPath = '/tmp/' . $tmp;
        if (file_put_contents($tmpPath, $json) === false) {
            return response()->json(['message' => 'Could not write temp allow-list'], 500);
        }

        [$response, $err] = pbx3_request_syscmd(
            '/bin/mkdir -p /etc/pbx3 && /bin/mv ' . escapeshellarg($tmpPath) . ' ' . escapeshellarg(self::ALLOWS_FILE)
        );
        if ($err !== null) {
            @unlink($tmpPath);

            return response()->json(['message' => 'Save failed', 'detail' => $err], 502);
        }

        return response()->json(['message' => 'Firewall allow-list saved', 'profile' => $profile, 'count' => count($rules)], 200);
    }

    /** Compat POST firewalls/ipv4 */
    public function ipv4save(Request $request)
    {
        // Legacy Shorewall sent { rules: ["ACCEPT …", …] }. Reject with guidance.
        if ($request->has('rules') && is_array($request->input('rules')) && $this->looksLikeLegacyShorewallLines($request->input('rules'))) {
            return response()->json([
                'message' => 'Shorewall raw lines are no longer accepted. POST structured rules: { profile, rules:[{action,proto,port,from,comment}] }.',
            ], 422);
        }

        return $this->save($request);
    }

    public function ipv6save(Request $request)
    {
        return response()->json([
            'message' => 'IPv6 is unified under UFW. Use POST firewalls (or firewalls/ipv4) with the structured allow-list.',
        ], 410);
    }

    /**
     * PUT firewalls — apply allow-list via ufw-apply-baseline.sh.
     */
    public function apply()
    {
        if (!is_readable(self::ALLOWS_FILE)) {
            return response()->json([
                'message' => 'Missing ' . self::ALLOWS_FILE . '. Save rules first or run ufw-apply-baseline.sh fleet|solo.',
            ], 404);
        }

        pbx3_update_fqdn_inline_optional(); // no-op under UFW; kept for hook compatibility

        [$rc, $err] = pbx3_request_syscmd('/opt/pbx3/scripts/ufw-apply-baseline.sh 2>&1');
        if ($err !== null) {
            return response()->json(['message' => 'UFW apply failed', 'detail' => $err, 'output' => $rc], 502);
        }
        if ($rc !== null && (stripos($rc, 'ufw-apply-baseline:') !== false && stripos($rc, 'die') !== false)) {
            return response()->json(['message' => 'UFW apply failed', 'output' => array_filter(explode("\n", $rc))], 500);
        }

        return response()->json(['message' => 'UFW rules applied', 'output' => trim((string) $rc)], 200);
    }

    public function ipv4restart()
    {
        return $this->apply();
    }

    public function ipv6restart()
    {
        return response()->json([
            'message' => 'IPv6 is unified under UFW. Use PUT firewalls (or firewalls/ipv4).',
        ], 410);
    }

    /** @return array{profile:?string,rules:array}|null */
    private function readAllows(): ?array
    {
        if (!is_readable(self::ALLOWS_FILE)) {
            return null;
        }
        $raw = @file_get_contents(self::ALLOWS_FILE);
        if ($raw === false || $raw === '') {
            return null;
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['rules']) || !is_array($data['rules'])) {
            return null;
        }

        return [
            'profile' => $data['profile'] ?? null,
            'rules' => array_values($data['rules']),
        ];
    }

    private function validateRuleShape(string $proto, string $port, string $from): ?string
    {
        if ($from !== 'any' && !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+(\/[0-9]+)?$/', $from)) {
            return "from must be 'any' or an IPv4/CIDR (got: {$from})";
        }
        if ($proto === 'icmp') {
            return null;
        }
        if ($proto === 'all') {
            if ($port !== '' && strtoupper($port) !== 'N/A') {
                return 'proto=all must not set a port';
            }

            return null;
        }
        if ($port === '' || !preg_match('/^[0-9]+(:[0-9]+)?$/', $port)) {
            return "port must be a number or range like 10000:20000 (got: {$port})";
        }

        return null;
    }

    private function looksLikeLegacyShorewallLines(array $rules): bool
    {
        if ($rules === []) {
            return false;
        }
        $first = $rules[0];
        if (!is_string($first)) {
            return false;
        }

        return (bool) preg_match('/^(ACCEPT|DROP|REJECT|INLINE)\b/i', trim($first));
    }
}
