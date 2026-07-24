<?php

namespace App\Services\Cdr;

use PDO;
use RuntimeException;

/**
 * Velocity V1 — path-safe CDR fixture decks for lab / unit tests.
 *
 * Default refuses the live Asterisk master.db path unless --allow-live.
 * Spec: FLEET_TOLL_FRAUD_VELOCITY_REQUIREMENTS.md § CDR fixture.
 */
final class CdrFixtureService
{
    public const LIVE_DEFAULT_PATH = '/var/log/asterisk/master.db';

    /** Lab high-cost prefix used by the IRSF deck (matches config default). */
    public const LAB_PREMIUM_PREFIX = '00900';

    public function __construct(
        private readonly CdrIndexService $index,
    ) {
    }

    /**
     * @param  array{
     *   path?: string|null,
     *   deck?: string,
     *   count?: int,
     *   src?: string,
     *   accountcode?: string,
     *   allow_live?: bool,
     *   force?: bool
     * }  $options
     * @return array{path: string, created: bool, inserted: int, deck: string}
     */
    public function seed(array $options = []): array
    {
        $path = $this->resolvePath(isset($options['path']) ? (string) $options['path'] : null);
        $allowLive = (bool) ($options['allow_live'] ?? false);
        $force = (bool) ($options['force'] ?? false)
            || filter_var(env('PBX3_CDR_FIXTURE', false), FILTER_VALIDATE_BOOL);

        $this->assertWritable($path, $allowLive, $force);

        $deck = strtolower(trim((string) ($options['deck'] ?? 'irsf')));
        $count = max(1, (int) ($options['count'] ?? 12));
        $src = trim((string) ($options['src'] ?? '1001'));
        $accountcode = trim((string) ($options['accountcode'] ?? 'labtenant'));

        $created = false;
        if (! is_file($path)) {
            $dir = dirname($path);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException("Cannot create directory for CDR fixture: {$dir}");
            }
            touch($path);
            $created = true;
        }

        $pdo = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        CdrSchema::ensureTable($pdo);

        $rows = match ($deck) {
            'irsf' => $this->deckIrsf($count, $src, $accountcode),
            'failed-scan' => $this->deckFailedScan($count, $src, $accountcode),
            'internal-noise' => $this->deckInternalNoise($count, $src, $accountcode),
            'mixed' => array_merge(
                $this->deckIrsf(max(1, (int) ceil($count * 0.6)), $src, $accountcode),
                $this->deckInternalNoise(max(1, (int) floor($count * 0.4)), $src, $accountcode),
            ),
            default => throw new RuntimeException("Unknown CDR fixture deck: {$deck}"),
        };

        $ins = $pdo->prepare(
            'INSERT INTO cdr (calldate, clid, src, dst, dcontext, channel, dstchannel,'
            .' lastapp, lastdata, duration, billsec, disposition, amaflags,'
            .' accountcode, uniqueid, userfield, linkedid)'
            .' VALUES (:calldate, :clid, :src, :dst, :dcontext, :channel, :dstchannel,'
            .' :lastapp, :lastdata, :duration, :billsec, :disposition, :amaflags,'
            .' :accountcode, :uniqueid, :userfield, :linkedid)'
        );

        $inserted = 0;
        foreach ($rows as $row) {
            $ins->execute($row);
            $inserted++;
        }

        return [
            'path' => $path,
            'created' => $created,
            'inserted' => $inserted,
            'deck' => $deck,
        ];
    }

    public function resolvePath(?string $override): string
    {
        $path = $override !== null && trim($override) !== ''
            ? trim($override)
            : $this->index->path();

        return $path;
    }

    public function isLivePath(string $path): bool
    {
        $real = realpath($path) ?: $path;
        $live = realpath(self::LIVE_DEFAULT_PATH) ?: self::LIVE_DEFAULT_PATH;

        return $real === $live || $path === self::LIVE_DEFAULT_PATH;
    }

    private function assertWritable(string $path, bool $allowLive, bool $force): void
    {
        if ($this->isLivePath($path) && ! $allowLive) {
            throw new RuntimeException(
                'Refusing to write live Asterisk master.db. Point PBX3_CDR_SQLITE_PATH'
                .' at a lab copy, pass --path=, or use --allow-live (explicit).'
            );
        }

        if (! $force && ! $allowLive && app()->environment('production')) {
            throw new RuntimeException(
                'CDR fixture blocked in production. Set PBX3_CDR_FIXTURE=1 or pass --force,'
                .' and prefer a non-live --path=.'
            );
        }
    }

    /**
     * IRSF-shaped burst: N recent outbound rows to lab premium prefix, same src.
     *
     * @return list<array<string, mixed>>
     */
    private function deckIrsf(int $count, string $src, string $accountcode): array
    {
        $rows = [];
        $now = time();
        for ($i = 0; $i < $count; $i++) {
            $calldate = date('Y-m-d H:i:s', $now - (($count - $i) * 12));
            $dst = self::LAB_PREMIUM_PREFIX.sprintf('%07d', 1000000 + $i);
            $uid = 'fix-irsf-'.bin2hex(random_bytes(6)).'-'.$i;
            $rows[] = $this->row([
                'calldate' => $calldate,
                'src' => $src,
                'dst' => $dst,
                'dcontext' => 'from-internal',
                'channel' => 'PJSIP/'.$src.'-00000'.sprintf('%03d', $i),
                'lastapp' => 'Dial',
                'duration' => 45 + ($i % 5),
                'billsec' => 40 + ($i % 5),
                'disposition' => 'ANSWERED',
                'accountcode' => $accountcode,
                'uniqueid' => $uid,
                'linkedid' => $uid,
            ]);
        }

        return $rows;
    }

    /**
     * Failed / short-call scanning toward premium destinations.
     *
     * @return list<array<string, mixed>>
     */
    private function deckFailedScan(int $count, string $src, string $accountcode): array
    {
        $rows = [];
        $now = time();
        for ($i = 0; $i < $count; $i++) {
            $calldate = date('Y-m-d H:i:s', $now - (($count - $i) * 8));
            $dst = self::LAB_PREMIUM_PREFIX.sprintf('%07d', 2000000 + $i);
            $uid = 'fix-fail-'.bin2hex(random_bytes(6)).'-'.$i;
            $rows[] = $this->row([
                'calldate' => $calldate,
                'src' => $src,
                'dst' => $dst,
                'dcontext' => 'from-internal',
                'channel' => 'PJSIP/'.$src.'-f'.sprintf('%03d', $i),
                'lastapp' => 'Dial',
                'duration' => 2 + ($i % 3),
                'billsec' => 0,
                'disposition' => ($i % 2 === 0) ? 'NO ANSWER' : 'BUSY',
                'accountcode' => $accountcode,
                'uniqueid' => $uid,
                'linkedid' => $uid,
            ]);
        }

        return $rows;
    }

    /**
     * Internal dials that velocity query must exclude.
     *
     * @return list<array<string, mixed>>
     */
    private function deckInternalNoise(int $count, string $src, string $accountcode): array
    {
        $rows = [];
        $now = time();
        $locals = ['1002', '2001', 'vqcwd4', '08jzwn'];
        for ($i = 0; $i < $count; $i++) {
            $calldate = date('Y-m-d H:i:s', $now - (($count - $i) * 20));
            $dst = $locals[$i % count($locals)];
            $uid = 'fix-int-'.bin2hex(random_bytes(6)).'-'.$i;
            $rows[] = $this->row([
                'calldate' => $calldate,
                'src' => $src,
                'dst' => $dst,
                'dcontext' => 'from-internal',
                'channel' => 'PJSIP/'.$src.'-i'.sprintf('%03d', $i),
                'lastapp' => 'Dial',
                'duration' => 15,
                'billsec' => 12,
                'disposition' => 'ANSWERED',
                'accountcode' => $accountcode,
                'uniqueid' => $uid,
                'linkedid' => $uid,
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(array $overrides): array
    {
        $src = (string) ($overrides['src'] ?? '1001');

        return array_merge([
            'calldate' => date('Y-m-d H:i:s'),
            'clid' => '"Fixture" <'.$src.'>',
            'src' => $src,
            'dst' => '',
            'dcontext' => 'from-internal',
            'channel' => 'PJSIP/'.$src.'-00000000',
            'dstchannel' => '',
            'lastapp' => 'Dial',
            'lastdata' => '',
            'duration' => 0,
            'billsec' => 0,
            'disposition' => 'ANSWERED',
            'amaflags' => 3,
            'accountcode' => '',
            'uniqueid' => 'fix-'.bin2hex(random_bytes(8)),
            'userfield' => 'pbx3-cdr-fixture',
            'linkedid' => '',
        ], $overrides);
    }
}
