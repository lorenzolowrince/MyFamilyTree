<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Creates the S3 bucket for local development (RustFS container) if it does not exist.
 * Staging and production buckets are provisioned outside the app, so this refuses to run there.
 */
#[Signature('storage:ensure-bucket')]
#[Description('Create the local S3-compatible bucket if it does not exist (local/testing only)')]
class EnsureStorageBucket extends Command
{
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Buckets are only created automatically in local development.');

            return self::FAILURE;
        }

        /** @var array{key: string, secret: string, region: string, bucket: string, endpoint: ?string, use_path_style_endpoint: bool} $config */
        $config = config('filesystems.disks.s3');

        $client = new S3Client([
            'version' => 'latest',
            'region' => $config['region'],
            'endpoint' => $config['endpoint'],
            'use_path_style_endpoint' => $config['use_path_style_endpoint'],
            'credentials' => ['key' => $config['key'], 'secret' => $config['secret']],
        ]);

        if ($client->doesBucketExistV2($config['bucket'])) {
            $this->info("Bucket [{$config['bucket']}] already exists.");

            return self::SUCCESS;
        }

        $client->createBucket(['Bucket' => $config['bucket']]);
        $this->info("Bucket [{$config['bucket']}] created.");

        return self::SUCCESS;
    }
}
