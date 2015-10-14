<?php
/**
 * @author Silvan
 */

namespace Mpom;

use Google_Client;
use Google_Service_Gmail;

class Gmail
{
    protected $config;

    public function __construct($config) {
        $this->config = $config;
    }

    /**
     * Returns an authorized API client.
     * @return Google_Client the authorized client object
     */
    public function getClient()
    {
        $client = new Google_Client();
        $client->setApplicationName($this->config['gmail_application_name']);
        $client->setScopes( Google_Service_Gmail::GMAIL_COMPOSE . " " . Google_Service_Gmail::GMAIL_MODIFY);
        $client->setAuthConfigFile($this->config['gmail_client_secrets_path']);
        $client->setAccessType('offline');

        // Load previously authorized credentials from a file.
        $credentialsPath = $this->config['gmail_credentials_path'];
        if (file_exists($credentialsPath)) {
            $accessToken = file_get_contents($credentialsPath);
        } else {
            // Request authorization from the user.
            $authUrl = $client->createAuthUrl();
            printf("Open the following link in your browser:\n%s\n", $authUrl);
            print 'Enter verification code: ';
            $authCode = trim(fgets(STDIN));

            // Exchange authorization code for an access token.
            $accessToken = $client->authenticate($authCode);

            // Store the credentials to disk.
            if ( ! file_exists(dirname($credentialsPath))) {
                mkdir(dirname($credentialsPath), 0700, true);
            }
            file_put_contents($credentialsPath, $accessToken);
            printf("Credentials saved to %s\n", $credentialsPath);
        }
        $client->setAccessToken($accessToken);

        // Refresh the token if it's expired.
        if ($client->isAccessTokenExpired()) {
            $client->refreshToken($client->getRefreshToken());
            file_put_contents($credentialsPath, $client->getAccessToken());
        }

        return $client;
    }
}