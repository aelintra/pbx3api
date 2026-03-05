<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Certificates panel API: active source, Let's Encrypt status/renew, custom cert install/remove.
 * All privileged file access via syshelper. See CERTIFICATES_ADOPTION_PLAN.md.
 */
class CertificateController extends Controller
{
    private const CUSTOM_FULLCHAIN = '/opt/pbx3/etc/ssl/custom/fullchain.pem';
    private const CUSTOM_PRIVKEY = '/opt/pbx3/etc/ssl/custom/privkey.pem';
    private const LE_DOMAIN_FILE = '/opt/pbx3/etc/identity/le-domain';
    private const LE_LIVE_BASE = '/etc/letsencrypt/live';
    private const APPLY_SCRIPT = '/opt/pbx3/scripts/apply-active-cert.sh';

    /**
     * GET /certificates/active — which cert source is in use (custom | letsencrypt | snakeoil).
     */
    public function active()
    {
        $source = $this->determineActiveSource();
        return response()->json(['source' => $source], 200);
    }

    /**
     * GET /certificates/letsencrypt — status: configured, domain, expires_at, issuer.
     */
    public function letsencrypt()
    {
        [$domain, $domainErr] = $this->readLeDomain();
        if ($domainErr !== null || $domain === null || $domain === '') {
            return response()->json([
                'configured' => false,
                'domain' => null,
                'expires_at' => null,
                'issuer' => null,
            ], 200);
        }

        $fullchain = self::LE_LIVE_BASE . '/' . trim($domain) . '/fullchain.pem';
        [$exists] = pbx3_request_syscmd('test -f ' . escapeshellarg($fullchain) . ' && echo yes || echo no');
        if (trim($exists ?? '') !== 'yes') {
            return response()->json([
                'configured' => false,
                'domain' => trim($domain),
                'expires_at' => null,
                'issuer' => null,
            ], 200);
        }

        [$enddateOut, $enddateErr] = pbx3_request_syscmd(
            'openssl x509 -enddate -noout -in ' . escapeshellarg($fullchain) . ' 2>/dev/null'
        );
        $expiresAt = null;
        if ($enddateErr === null && preg_match('/notAfter=\s*(.+)/', $enddateOut ?? '', $m)) {
            $t = strtotime(trim($m[1]));
            if ($t !== false) {
                $expiresAt = date('Y-m-d', $t);
            }
        }

        [$issuerOut] = pbx3_request_syscmd(
            'openssl x509 -noout -issuer -in ' . escapeshellarg($fullchain) . ' 2>/dev/null'
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
        ], 200);
    }

    /**
     * POST /certificates/letsencrypt/setup — first-time Let's Encrypt: obtain cert for fqdn, write le-domain, apply.
     * Body: { "fqdn": "host.example.com", "email": "admin@example.com" }. PBX3_SYSCMD_TIMEOUT >= 90 recommended.
     */
    public function setup(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'fqdn' => 'required|string|max:253',
            'email' => 'required|email',
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed', 'errors' => $validator->errors()], 422);
        }
        $fqdn = trim($request->input('fqdn'));
        $email = $request->input('email');
        if ($fqdn === '') {
            return response()->json(['message' => 'FQDN is required.'], 422);
        }

        [$domain, $domainErr] = $this->readLeDomain();
        if ($domainErr === null && $domain !== null && $domain !== '') {
            $fullchain = self::LE_LIVE_BASE . '/' . trim($domain) . '/fullchain.pem';
            [$exists] = pbx3_request_syscmd('test -f ' . escapeshellarg($fullchain) . ' && echo yes || echo no');
            if (trim($exists ?? '') === 'yes') {
                return response()->json([
                    'message' => 'Let\'s Encrypt is already configured. Use Renew now to refresh the certificate.',
                ], 409);
            }
        }

        $cmd = '/opt/pbx3/scripts/le-first-cert.sh ' . escapeshellarg($fqdn) . ' ' . escapeshellarg($email) . ' 2>&1';
        [$out, $err] = pbx3_request_syscmd($cmd);
        if ($err !== null) {
            return response()->json(['message' => 'Setup failed', 'detail' => $err], 502);
        }
        return response()->json(['message' => 'Let\'s Encrypt certificate obtained.', 'output' => trim($out ?? '')], 200);
    }

    /**
     * POST /certificates/letsencrypt/renew — trigger renewal then deploy hook (reload nginx + Asterisk).
     */
    public function renew(Request $request)
    {
        [$domain, $domainErr] = $this->readLeDomain();
        if ($domainErr !== null || $domain === null || $domain === '') {
            return response()->json([
                'message' => 'Let\'s Encrypt is not configured (no le-domain).',
            ], 503);
        }

        $fullchain = self::LE_LIVE_BASE . '/' . trim($domain) . '/fullchain.pem';
        [$exists] = pbx3_request_syscmd('test -f ' . escapeshellarg($fullchain) . ' && echo yes || echo no');
        if (trim($exists ?? '') !== 'yes') {
            return response()->json([
                'message' => 'Let\'s Encrypt certificate not found.',
            ], 503);
        }

        // Open port 80, run certbot renew, close port 80 (PBX3_SYSCMD_TIMEOUT >= 90 recommended).
        [$out, $err] = pbx3_request_syscmd(
            '/opt/pbx3/scripts/le-renew-with-80.sh 2>&1'
        );
        if ($err !== null) {
            return response()->json(['message' => 'Renewal failed', 'detail' => $err], 502);
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

        if (!str_contains($certPem, '-----BEGIN') || !str_contains($keyPem, '-----BEGIN')) {
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

        if (!openssl_x509_check_private_key($cert, $key)) {
            return response()->json([
                'message' => 'Certificate and private key do not match.',
            ], 422);
        }

        $prefix = 'pbx3cert_' . Str::random(8);
        $tmpCert = '/tmp/' . $prefix . '_fullchain.pem';
        $tmpKey = '/tmp/' . $prefix . '_privkey.pem';
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
            '/bin/mv ' . escapeshellarg($tmpCert) . ' ' . escapeshellarg(self::CUSTOM_FULLCHAIN) . ' && ' .
            '/bin/mv ' . escapeshellarg($tmpKey) . ' ' . escapeshellarg(self::CUSTOM_PRIVKEY)
        );
        if ($mvErr !== null) {
            @unlink($tmpCert);
            @unlink($tmpKey);
            return response()->json(['message' => 'Failed to install certificate', 'detail' => $mvErr], 502);
        }

        [$_, $chmodErr] = pbx3_request_syscmd(
            '/bin/chmod 644 ' . escapeshellarg(self::CUSTOM_FULLCHAIN) . ' && ' .
            '/bin/chmod 600 ' . escapeshellarg(self::CUSTOM_PRIVKEY)
        );
        if ($chmodErr !== null) {
            // non-fatal; cert is in place
        }

        [$_, $applyErr] = pbx3_request_syscmd(self::APPLY_SCRIPT . ' 2>&1');
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
            '/bin/rm -f ' . escapeshellarg(self::CUSTOM_FULLCHAIN) . ' ' . escapeshellarg(self::CUSTOM_PRIVKEY)
        );
        if ($err !== null) {
            return response()->json(['message' => 'Failed to remove certificate', 'detail' => $err], 502);
        }

        [$_, $applyErr] = pbx3_request_syscmd(self::APPLY_SCRIPT . ' 2>&1');
        if ($applyErr !== null) {
            return response()->json([
                'message' => 'Certificate removed but reload failed. Fix config and reload nginx/Asterisk manually.',
                'detail' => $applyErr,
            ], 500);
        }

        return response()->json(['message' => 'Purchased certificate removed.'], 200);
    }

    private function determineActiveSource(): string
    {
        if ($this->customCertInstalled()) {
            return 'custom';
        }
        [$domain, $domainErr] = $this->readLeDomain();
        if ($domainErr === null && $domain !== null && $domain !== '') {
            $fullchain = self::LE_LIVE_BASE . '/' . trim($domain) . '/fullchain.pem';
            [$exists] = pbx3_request_syscmd('test -f ' . escapeshellarg($fullchain) . ' && echo yes || echo no');
            if (trim($exists ?? '') === 'yes') {
                return 'letsencrypt';
            }
        }
        return 'snakeoil';
    }

    private function customCertInstalled(): bool
    {
        [$out] = pbx3_request_syscmd(
            'test -f ' . escapeshellarg(self::CUSTOM_FULLCHAIN) . ' && test -f ' . escapeshellarg(self::CUSTOM_PRIVKEY) . ' && echo yes || echo no'
        );
        return trim($out ?? '') === 'yes';
    }

    /** @return array{0: string|null, 1: string|null} [domain content, error] */
    private function readLeDomain(): array
    {
        [$out, $err] = pbx3_request_syscmd('cat ' . escapeshellarg(self::LE_DOMAIN_FILE) . ' 2>/dev/null');
        if ($err !== null || $out === null) {
            return [null, $err];
        }
        return [trim($out), null];
    }
}
