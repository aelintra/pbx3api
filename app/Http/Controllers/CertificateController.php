<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Certificates panel API: active source, Let's Encrypt status/renew, custom cert install/remove.
 * All privileged file access via syshelper. See pbx3/workingdocs/TLS_AND_CERTIFICATES.md and CERTIFICATES_PANEL_AND_API.md.
 */
class CertificateController extends Controller
{
    private const CUSTOM_FULLCHAIN = '/opt/pbx3/etc/ssl/custom/fullchain.pem';

    private const CUSTOM_PRIVKEY = '/opt/pbx3/etc/ssl/custom/privkey.pem';

    private const LE_DOMAIN_FILE = '/opt/pbx3/etc/identity/le-domain';

    private const LE_LIVE_BASE = '/etc/letsencrypt/live';

    private const APPLY_SCRIPT = '/opt/pbx3/scripts/apply-active-cert.sh';

    private const LE_FIRST_MULTI_SCRIPT = '/opt/pbx3/scripts/le-first-cert-multi.sh';

    private const LE_SYNC_SANS_SCRIPT = '/opt/pbx3/scripts/le-sync-cert-sans.sh';

    /** Long-running certbot via syshelper (Option A Step 2). */
    private const LE_SYSCMD_TIMEOUT = 120;

    /**
     * GET /certificates/active — which cert source is in use (custom | letsencrypt | snakeoil).
     */
    public function active()
    {
        $source = $this->determineActiveSource();

        return response()->json(['source' => $source], 200);
    }

    /**
     * GET /certificates/letsencrypt — status: configured, domain, expires_at, issuer, domains[] (tenant FQDNs).
     */
    public function letsencrypt()
    {
        $domains = $this->tenantFqdnSortedList();
        [$domain, $domainErr] = $this->readLeDomain();
        if ($domainErr !== null || $domain === null || $domain === '') {
            return response()->json([
                'configured' => false,
                'domain' => null,
                'expires_at' => null,
                'issuer' => null,
                'domains' => $domains,
            ], 200);
        }

        $fullchain = self::LE_LIVE_BASE.'/'.trim($domain).'/fullchain.pem';
        [$exists] = pbx3_request_syscmd('test -f '.escapeshellarg($fullchain).' && echo yes || echo no');
        if (trim($exists ?? '') !== 'yes') {
            return response()->json([
                'configured' => false,
                'domain' => trim($domain),
                'expires_at' => null,
                'issuer' => null,
                'domains' => $domains,
            ], 200);
        }

        [$enddateOut, $enddateErr] = pbx3_request_syscmd(
            'openssl x509 -enddate -noout -in '.escapeshellarg($fullchain).' 2>/dev/null'
        );
        $expiresAt = null;
        if ($enddateErr === null && preg_match('/notAfter=\s*(.+)/', $enddateOut ?? '', $m)) {
            $t = strtotime(trim($m[1]));
            if ($t !== false) {
                $expiresAt = date('Y-m-d', $t);
            }
        }

        [$issuerOut] = pbx3_request_syscmd(
            'openssl x509 -noout -issuer -in '.escapeshellarg($fullchain).' 2>/dev/null'
        );
        $issuer = null;
        if ($issuerOut !== null && preg_match('/issuer=(.+)/', $issuerOut, $m)) {
            $issuer = trim($m[1]);
        }

        return response()->json([
            'configured' => true,
            'domain' => trim($domain),
            'expires_at' => $expiresAt,
            'issuer' => $issuer,
            'domains' => $domains,
        ], 200);
    }

    /**
     * POST /certificates/letsencrypt/setup — first-time LE: all tenant FQDNs as SANs (Option A).
     * Body: { "email": "admin@example.com" } — optional legacy { "fqdn", "email" } ignored for SAN list (built from tenants).
     * PBX3_SYSCMD_TIMEOUT or per-call 120s recommended for certbot.
     */
    public function setup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'fqdn' => 'sometimes|string|max:253',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $email = $request->input('email');

        $sans = $this->tenantFqdnSortedList();
        if ($sans === []) {
            return response()->json([
                'message' => 'No tenant FQDNs in database; cannot build certificate SAN list.',
            ], 422);
        }
        $primary = $sans[0];

        [$domain, $domainErr] = $this->readLeDomain();
        if ($domainErr === null && $domain !== null && $domain !== '') {
            $fullchain = self::LE_LIVE_BASE.'/'.trim($domain).'/fullchain.pem';
            [$exists] = pbx3_request_syscmd('test -f '.escapeshellarg($fullchain).' && echo yes || echo no');
            if (trim($exists ?? '') === 'yes') {
                return response()->json([
                    'message' => 'Let\'s Encrypt is already configured. Use Renew or Sync to refresh the certificate.',
                ], 409);
            }
        }

        $cmdParts = [self::LE_FIRST_MULTI_SCRIPT, escapeshellarg($primary), escapeshellarg($email)];
        foreach (array_slice($sans, 1) as $extra) {
            $cmdParts[] = escapeshellarg($extra);
        }
        $cmd = implode(' ', $cmdParts).' 2>&1';
        [$out, $err] = pbx3_request_syscmd($cmd, self::LE_SYSCMD_TIMEOUT);
        if ($err !== null) {
            return response()->json(['message' => 'Setup failed', 'detail' => $err], 502);
        }

        // syshelper does not return shell exit status; certbot can fail while still returning stdout.
        if (! $this->leFullchainExistsForLiveName(trim($primary))) {
            return response()->json([
                'message' => 'Let\'s Encrypt setup did not produce certificate files (HTTP-01 requires port 80 reachable from the internet for each name on the certificate).',
                'detail' => trim($out ?? ''),
            ], 502);
        }
        if ($this->certbotOutputLooksLikeFailure($out)) {
            return response()->json([
                'message' => 'Let\'s Encrypt setup reported a certbot error (certificate files may be stale — verify on disk).',
                'detail' => trim($out ?? ''),
            ], 502);
        }

        return response()->json([
            'message' => 'Let\'s Encrypt certificate obtained.',
            'output' => trim($out ?? ''),
            'domains' => $sans,
        ], 200);
    }

    /**
     * POST /certificates/letsencrypt/sync — manual re-issue with current tenant FQDN list (same cert name / le-domain).
     */
    public function sync(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $email = $request->input('email');

        [$domain, $domainErr] = $this->readLeDomain();
        if ($domainErr !== null || $domain === null || $domain === '') {
            return response()->json([
                'message' => 'Let\'s Encrypt is not configured (no le-domain). Use Setup first.',
            ], 503);
        }

        $fullchain = self::LE_LIVE_BASE.'/'.trim($domain).'/fullchain.pem';
        [$exists] = pbx3_request_syscmd('test -f '.escapeshellarg($fullchain).' && echo yes || echo no');
        if (trim($exists ?? '') !== 'yes') {
            return response()->json([
                'message' => 'Let\'s Encrypt certificate files not found.',
            ], 503);
        }

        $sans = $this->tenantFqdnSortedList();
        if ($sans === []) {
            return response()->json([
                'message' => 'No tenant FQDNs in database; cannot build certificate SAN list.',
            ], 422);
        }
        if (trim($domain) !== $sans[0]) {
            return response()->json([
                'message' => 'Primary tenant FQDN must match le-domain certificate name.',
                'le_domain' => trim($domain),
                'expected_primary' => $sans[0],
            ], 409);
        }

        $cmdParts = [self::LE_SYNC_SANS_SCRIPT, escapeshellarg($email)];
        foreach ($sans as $fq) {
            $cmdParts[] = escapeshellarg($fq);
        }
        $cmd = implode(' ', $cmdParts).' 2>&1';
        [$out, $err] = pbx3_request_syscmd($cmd, self::LE_SYSCMD_TIMEOUT);
        if ($err !== null) {
            return response()->json(['message' => 'Sync failed', 'detail' => $err], 502);
        }

        if (! $this->leFullchainExistsForLiveName(trim($domain))) {
            return response()->json([
                'message' => 'Let\'s Encrypt sync did not leave certificate files in place.',
                'detail' => trim($out ?? ''),
            ], 502);
        }
        if ($this->certbotOutputLooksLikeFailure($out)) {
            return response()->json([
                'message' => 'Let\'s Encrypt sync appears to have failed (see certbot output).',
                'detail' => trim($out ?? ''),
            ], 502);
        }

        return response()->json([
            'message' => 'Certificate re-issued with current tenant FQDN list.',
            'output' => trim($out ?? ''),
            'domains' => $sans,
        ], 200);
    }

    /**
     * POST /certificates/letsencrypt/renew — trigger renewal then deploy hook (reload nginx + Asterisk).
     */
    public function renew()
    {
        [$domain, $domainErr] = $this->readLeDomain();
        if ($domainErr !== null || $domain === null || $domain === '') {
            return response()->json([
                'message' => 'Let\'s Encrypt is not configured (no le-domain).',
            ], 503);
        }

        $fullchain = self::LE_LIVE_BASE.'/'.trim($domain).'/fullchain.pem';
        [$exists] = pbx3_request_syscmd('test -f '.escapeshellarg($fullchain).' && echo yes || echo no');
        if (trim($exists ?? '') !== 'yes') {
            return response()->json([
                'message' => 'Let\'s Encrypt certificate not found.',
            ], 503);
        }

        // Open port 80, run certbot renew, close port 80 (PBX3_SYSCMD_TIMEOUT >= 90 recommended).
        [$out, $err] = pbx3_request_syscmd(
            '/opt/pbx3/scripts/le-renew-with-80.sh 2>&1',
            self::LE_SYSCMD_TIMEOUT
        );
        if ($err !== null) {
            return response()->json(['message' => 'Renewal failed', 'detail' => $err], 502);
        }
        if ($this->certbotOutputLooksLikeFailure($out)) {
            return response()->json([
                'message' => 'Renewal command reported a failure.',
                'detail' => trim($out ?? ''),
            ], 502);
        }
        if (stripos($out ?? '', 'No renewals were attempted') !== false ||
            stripos($out ?? '', 'not yet due for renewal') !== false) {
            return response()->json(['message' => 'No renewal needed.', 'output' => trim($out ?? '')], 200);
        }

        return response()->json(['message' => 'Renewal completed.', 'output' => trim($out ?? '')], 200);
    }

    /**
     * GET /certificates/custom — is a purchased cert installed?
     */
    public function customIndex()
    {
        $installed = $this->customCertInstalled();

        return response()->json(['installed' => $installed], 200);
    }

    /**
     * POST /certificates/custom — install purchased cert (multipart: cert, key; or JSON: cert, key).
     */
    public function customStore(Request $request)
    {
        $certPem = null;
        $keyPem = null;

        if ($request->hasFile('cert') && $request->hasFile('key')) {
            $certPem = $request->file('cert')->get();
            $keyPem = $request->file('key')->get();
        } elseif ($request->input('cert') && $request->input('key')) {
            $certPem = $request->input('cert');
            $keyPem = $request->input('key');
        } else {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => ['cert' => ['Cert and key are required (files or JSON).'], 'key' => []],
            ], 422);
        }

        $certPem = is_string($certPem) ? $certPem : '';
        $keyPem = is_string($keyPem) ? $keyPem : '';

        if (! str_contains($certPem, '-----BEGIN') || ! str_contains($keyPem, '-----BEGIN')) {
            return response()->json([
                'message' => 'Invalid PEM format. Provide fullchain.pem (certificate + chain) and privkey.pem.',
            ], 422);
        }

        $cert = @openssl_x509_read($certPem);
        if ($cert === false) {
            return response()->json([
                'message' => 'Invalid certificate PEM format.',
            ], 422);
        }

        $key = @openssl_pkey_get_private($keyPem);
        if ($key === false) {
            return response()->json([
                'message' => 'Invalid private key PEM format.',
            ], 422);
        }

        if (! openssl_x509_check_private_key($cert, $key)) {
            return response()->json([
                'message' => 'Certificate and private key do not match.',
            ], 422);
        }

        $prefix = 'pbx3cert_'.Str::random(8);
        $tmpCert = '/tmp/'.$prefix.'_fullchain.pem';
        $tmpKey = '/tmp/'.$prefix.'_privkey.pem';
        file_put_contents($tmpCert, $certPem);
        file_put_contents($tmpKey, $keyPem);
        chmod($tmpCert, 0644);
        chmod($tmpKey, 0600);

        [$_, $mkdirErr] = pbx3_request_syscmd('mkdir -p /opt/pbx3/etc/ssl/custom');
        if ($mkdirErr !== null) {
            @unlink($tmpCert);
            @unlink($tmpKey);

            return response()->json(['message' => 'Failed to create custom cert directory', 'detail' => $mkdirErr], 502);
        }

        [$_, $mvErr] = pbx3_request_syscmd(
            '/bin/mv '.escapeshellarg($tmpCert).' '.escapeshellarg(self::CUSTOM_FULLCHAIN).' && '.
            '/bin/mv '.escapeshellarg($tmpKey).' '.escapeshellarg(self::CUSTOM_PRIVKEY)
        );
        if ($mvErr !== null) {
            @unlink($tmpCert);
            @unlink($tmpKey);

            return response()->json(['message' => 'Failed to install certificate', 'detail' => $mvErr], 502);
        }

        [$_, $chmodErr] = pbx3_request_syscmd(
            '/bin/chmod 644 '.escapeshellarg(self::CUSTOM_FULLCHAIN).' && '.
            '/bin/chmod 600 '.escapeshellarg(self::CUSTOM_PRIVKEY)
        );
        if ($chmodErr !== null) {
            // non-fatal; cert is in place
        }

        [$_, $applyErr] = pbx3_request_syscmd(self::APPLY_SCRIPT.' 2>&1');
        if ($applyErr !== null) {
            return response()->json([
                'message' => 'Certificate installed but reload failed. Cert is in place; fix config and reload manually.',
                'detail' => $applyErr,
            ], 500);
        }

        return response()->json(['message' => 'Purchased certificate installed.'], 200);
    }

    /**
     * DELETE /certificates/custom — remove purchased cert; fallback to LE or snakeoil.
     */
    public function customDestroy()
    {
        [$_, $err] = pbx3_request_syscmd(
            '/bin/rm -f '.escapeshellarg(self::CUSTOM_FULLCHAIN).' '.escapeshellarg(self::CUSTOM_PRIVKEY)
        );
        if ($err !== null) {
            return response()->json(['message' => 'Failed to remove certificate', 'detail' => $err], 502);
        }

        [$_, $applyErr] = pbx3_request_syscmd(self::APPLY_SCRIPT.' 2>&1');
        if ($applyErr !== null) {
            return response()->json([
                'message' => 'Certificate removed but reload failed. Fix config and reload nginx/Asterisk manually.',
                'detail' => $applyErr,
            ], 500);
        }

        return response()->json(['message' => 'Purchased certificate removed.'], 200);
    }

    /**
     * Distinct non-empty cluster.fqdn values, default tenant first (Option A 2.1).
     *
     * @return list<string>
     */
    private function tenantFqdnSortedList(): array
    {
        $rows = Tenant::query()
            ->whereNotNull('fqdn')
            ->where('fqdn', '!=', '')
            ->orderByRaw("CASE WHEN pkey = 'default' THEN 0 ELSE 1 END")
            ->orderBy('pkey')
            ->get(['fqdn', 'pkey']);

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $f = trim((string) $row->fqdn);
            if ($f === '') {
                continue;
            }
            $k = strtolower($f);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $f;
        }

        return $out;
    }

    private function determineActiveSource(): string
    {
        if ($this->customCertInstalled()) {
            return 'custom';
        }
        [$domain, $domainErr] = $this->readLeDomain();
        if ($domainErr === null && $domain !== null && $domain !== '') {
            $fullchain = self::LE_LIVE_BASE.'/'.trim($domain).'/fullchain.pem';
            [$exists] = pbx3_request_syscmd('test -f '.escapeshellarg($fullchain).' && echo yes || echo no');
            if (trim($exists ?? '') === 'yes') {
                return 'letsencrypt';
            }
        }

        return 'snakeoil';
    }

    private function customCertInstalled(): bool
    {
        [$out] = pbx3_request_syscmd(
            'test -f '.escapeshellarg(self::CUSTOM_FULLCHAIN).' && test -f '.escapeshellarg(self::CUSTOM_PRIVKEY).' && echo yes || echo no'
        );

        return trim($out ?? '') === 'yes';
    }

    private function leFullchainExistsForLiveName(string $liveName): bool
    {
        $liveName = trim($liveName);
        if ($liveName === '') {
            return false;
        }
        $fullchain = self::LE_LIVE_BASE.'/'.$liveName.'/fullchain.pem';
        $privkey = self::LE_LIVE_BASE.'/'.$liveName.'/privkey.pem';
        [$ok] = pbx3_request_syscmd(
            'test -f '.escapeshellarg($fullchain).' && test -f '.escapeshellarg($privkey).' && echo yes || echo no'
        );

        return trim($ok ?? '') === 'yes';
    }

    /**
     * syshelper does not return the shell exit code; use this on certbot combined stdout/stderr.
     */
    private function certbotOutputLooksLikeFailure(?string $out): bool
    {
        $o = $out ?? '';
        if ($o === '') {
            return false;
        }
        if (stripos($o, 'Some challenges have failed') !== false) {
            return true;
        }
        if (stripos($o, 'All authorizations were not finalized') !== false) {
            return true;
        }
        if (stripos($o, 'All renewals failed') !== false) {
            return true;
        }
        if (stripos($o, 'Exiting abnormally') !== false) {
            return true;
        }
        if (preg_match('/^\s*Certbot failed/m', $o)) {
            return true;
        }

        return false;
    }

    /** @return array{0: string|null, 1: string|null} [domain content, error] */
    private function readLeDomain(): array
    {
        [$out, $err] = pbx3_request_syscmd('cat '.escapeshellarg(self::LE_DOMAIN_FILE).' 2>/dev/null');
        if ($err !== null || $out === null) {
            return [null, $err];
        }

        return [trim($out), null];
    }
}
