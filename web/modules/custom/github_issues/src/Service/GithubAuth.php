<?php

namespace Drupal\github_issues\Service;

use Drupal\Core\Config\ImmutableConfig;
use Firebase\JWT\JWT;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Service to authenticate with GitHub.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class GithubAuth {

  /**
   * The configuration service.
   */
  protected ImmutableConfig $config;

  /**
   * ListIssuesController constructor.
   *
   * @param ClientInterface $client
   *   The client interface used for communication
   * @param KeyRepositoryInterface $keyRepository
   *   The key repository interface for managing keys
   * @param ConfigFactoryInterface $configFactory
   *   The config factory interface for obtaining configuration settings
   */
  public function __construct(
    protected ClientInterface $client,
    protected KeyRepositoryInterface $keyRepository,
    ConfigFactoryInterface $configFactory
  ) {
    $this->config = $configFactory->get('github_issues.settings');
  }

  /**
   * Generates a JSON Web Token (JWT) using the private key stored
   * in the key repository.
   *
   * The JWT payload includes the current time as the issued at (iat)
   * and expiration (exp) time, and the application ID as the issuer (iss).
   *
   * @return string
   *   The generated JWT.
   */
  public function generateJWT(): string {
    $privateKey = $this->keyRepository
      ->getKey($this->config->get('key'))
      ->getKeyValue();
    $payload = [
      'iat' => time(),
      'exp' => time() + (10 * 60), // 10 minutes expiry.
      'iss' => $this->config->get('app_id'),
    ];

    return JWT::encode($payload, $privateKey, 'RS256');
  }

  /**
   * Get the token value.
   *
   * @return string
   *   The token value.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getToken(): string {
    $jwt = $this->generateJWT();
    $installationId = $this->config->get('installation_id');
    $url = "https://api.github.com/app/installations/{$installationId}/access_tokens";

    $response = $this->client->post($url, [
      'headers' => [
        'Authorization' => 'Bearer ' . $jwt,
        'Accept' => 'application/vnd.github+json',
      ],
    ]);

    $data = json_decode($response->getBody(), TRUE);

    return $data['token'];
  }

}
