<?php

namespace App\Services\Fleet;

use App\Models\Sysglobal;
use Illuminate\Support\Facades\Log;

/**
 * Dual-write globals.sitename → catalog label when Gatekeeper is configured.
 *
 * @see pbx3/workingdocs/FLEET_NAMING_LOCK.md
 */
final class InstanceCatalogLabelSync
{
    public function __construct(
        private readonly GatekeeperCatalogClient $client,
    ) {}

    /**
     * After sitename was persisted: push label to catalog, or revert sitename and throw.
     *
     * @throws \RuntimeException when catalog path is configured but sync fails
     */
    public function syncSitenameOrRevert(Sysglobal $sysglobal, ?string $previousSitename): void
    {
        if (! $this->client->isConfigured()) {
            return;
        }

        $id = trim((string) ($sysglobal->id ?? ''));
        if ($id === '') {
            throw new \RuntimeException(
                'Cannot sync Site Name to fleet catalog: globals.id missing',
                503
            );
        }

        $label = trim((string) ($sysglobal->sitename ?? ''));
        if ($label === '') {
            $label = trim((string) ($sysglobal->shortuid ?? ''));
        }

        try {
            $this->client->patchInstance($id, [
                'label' => $label,
                'updated_by' => 'node-sitename-sync',
            ]);
        } catch (\Throwable $e) {
            Log::warning('sitename catalog sync failed; reverting', [
                'instance_id' => $id,
                'error' => $e->getMessage(),
            ]);
            $sysglobal->sitename = $previousSitename;
            $sysglobal->save();
            throw new \RuntimeException(
                'Site Name not saved: fleet catalog update failed ('.$e->getMessage()
                .'). Fix Gatekeeper/catalog access or clear PBX3_GATEKEEPER_* for solo.',
                503,
                $e
            );
        }
    }
}
