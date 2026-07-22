<?php

namespace App\Providers;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleServiceDrive;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);

        $this->registerGoogleDriveDisk();
    }

    private function registerGoogleDriveDisk(): void
    {
        Storage::extend('google', function ($app, $config) {
            $client = new GoogleClient();
            $client->setClientId($config['clientId']);
            $client->setClientSecret($config['clientSecret']);

            // refreshToken() swallows a failed exchange - Google's response
            // comes back as an array without an `access_token` key (e.g.
            // {"error": "invalid_grant", ...}) rather than an exception, so
            // the client is left with no token and every subsequent Drive
            // API call fails with a generic, unrelated-looking 401 far from
            // here. Surface the real reason immediately instead.
            $token = $client->refreshToken($config['refreshToken']);
            if (! isset($token['access_token'])) {
                throw new \RuntimeException(
                    'Google Drive OAuth token refresh failed: ' . json_encode($token)
                );
            }

            $options = [];
            if (! empty($config['sharedFolderId'] ?? null)) {
                $options['sharedFolderId'] = $config['sharedFolderId'];
            }

            $service = new GoogleServiceDrive($client);
            $adapter = new GoogleDriveAdapter($service, null, $options);
            $driver = new Filesystem($adapter);

            return new FilesystemAdapter($driver, $adapter, $config);
        });
    }
}