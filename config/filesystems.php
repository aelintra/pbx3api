<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'throw' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
            'throw' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
        ],

        /*
         * Org directory bucket (catalog, instance backups). Uses PBX3_ORG_BUCKET;
         * credentials via instance IAM role or AWS_* env. Requires:
         * composer require league/flysystem-aws-s3-v3
         */
        'pbx3_org' => [
            'driver' => 's3',
            // Empty .env values must be null so EC2 instance role credentials are used.
            'key' => env('AWS_ACCESS_KEY_ID') ?: null,
            'secret' => env('AWS_SECRET_ACCESS_KEY') ?: null,
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('PBX3_ORG_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'options' => [
                'http' => [
                    'connect_timeout' => (int) env('PBX3_S3_CONNECT_TIMEOUT', 3),
                    'timeout' => (int) env('PBX3_S3_HTTP_TIMEOUT', 120),
                ],
            ],
            // Must throw or verify exists — false negatives logged "upload complete" with writeStream+Tagging.
            'throw' => true,
        ],

        'backups' => [
            'driver' => 'local',
            'root' => '/opt/pbx3/bkup',
            'throw' => false,
        ],

        'snapshots' => [
            'driver' => 'local',
            'root' => '/opt/pbx3/snap',
            'throw' => false,
        ],

        'greetings' => [
            'driver' => 'local',
            // Root is the base sounds directory; tenant greetings live under {cluster_shortuid}/
            'root' => '/usr/share/asterisk/sounds',
            'throw' => false,
        ],

        /*
         * Call recordings (Phase R1 — local-first, no S3). Asterisk MixMonitor
         * writes finished wav files to {root}/{tenant_shortuid}/, named
         * {epoch}-{tenant}-{calledid}-{clid}.wav. Root is configurable per node
         * (PBX3_RECORDINGS_ROOT) but defaults to the live MixMonitor spool.
         */
        'recordings' => [
            'driver' => 'local',
            'root' => env('PBX3_RECORDINGS_ROOT', '/var/spool/asterisk/monitor'),
            'throw' => false,
        ],

        /*
         * Call recordings local archive (Phase R1.5). Offload job moves stable
         * spool wavs to {root}/{tenant}/{yyyy}/{mm}/{dd}/{filename}.wav.
         * public visibility → dirs 0755 / files 0644 so the web (php-fpm) user
         * can traverse the tree the root-run offload/cron creates.
         */
        'recordings_archive' => [
            'driver' => 'local',
            'root' => env('PBX3_RECORDINGS_ARCHIVE_ROOT', '/opt/pbx3/media/recordings'),
            'visibility' => 'public',
            'throw' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
