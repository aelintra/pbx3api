<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TotpService
{
    public const RECOVERY_CODE_COUNT = 8;

    public function __construct(
        private readonly Google2FA $google2fa = new Google2FA,
    ) {}

    public function issuer(): string
    {
        $issuer = trim((string) config('pbx3_auth.totp_issuer', 'Aelintra PBX'));

        return $issuer !== '' ? $issuer : 'Aelintra PBX';
    }

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function encryptSecret(string $plain): string
    {
        return Crypt::encryptString($plain);
    }

    public function decryptSecret(?string $encrypted): ?string
    {
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function otpauthUrl(User $user, string $plainSecret): string
    {
        return $this->google2fa->getQRCodeUrl(
            $this->issuer(),
            (string) $user->email,
            $plainSecret
        );
    }

    /**
     * SVG data URI for QR display in SPA.
     */
    public function qrDataUri(string $otpauthUrl): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(220),
            new SvgImageBackEnd
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($otpauthUrl);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    public function verify(string $plainSecret, string $code): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if ($code === '' || ! ctype_digit($code)) {
            return false;
        }

        return (bool) $this->google2fa->verifyKey($plainSecret, $code, 1);
    }

    /**
     * @return list<string> plain recovery codes (show once)
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $codes[] = strtoupper(Str::random(4).'-'.Str::random(4));
        }

        return $codes;
    }

    /**
     * @param  list<string>  $plainCodes
     */
    public function hashRecoveryCodes(array $plainCodes): string
    {
        $hashed = array_map(static fn (string $c) => Hash::make($c), $plainCodes);

        return json_encode(array_values($hashed), JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<string>
     */
    public function decodeRecoveryHashes(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, static fn ($h) => is_string($h) && $h !== ''))
            : [];
    }

    /**
     * Consume a matching recovery code (single-use). Returns true if used.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = strtoupper(trim(preg_replace('/\s+/', '', $code) ?? ''));
        if ($code === '') {
            return false;
        }

        $hashes = $this->decodeRecoveryHashes($user->two_factor_recovery_codes);
        foreach ($hashes as $i => $hash) {
            if (Hash::check($code, $hash)) {
                unset($hashes[$i]);
                $user->two_factor_recovery_codes = json_encode(array_values($hashes), JSON_THROW_ON_ERROR);
                $user->save();

                return true;
            }
        }

        return false;
    }

    public function clearTwoFactor(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_recovery_codes = null;
        $user->save();
    }
}
