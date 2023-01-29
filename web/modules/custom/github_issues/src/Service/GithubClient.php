<?php

namespace Drupal\github_issues\Service;

use Drupal\Core\Config\ImmutableConfig;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\key\KeyRepositoryInterface;

/**
 * Service to communicate with GitHub.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class GithubClient {

  private const API_URL = 'https://api.github.com';
  private const REPOS_ENDPOINT = '/repos';

  /**
   * The configuration service.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected ImmutableConfig $config;

  /**
   * ListIssuesController constructor.
   *
   * @param ClientInterface $client
   *   The client interface used for communication
   * @param KeyRepositoryInterface $keyRepository
   *   The key repository interface for managing keys
   * @param \Drupal\github_issues\Service\GithubAuth $githubAuth
   *   The GitHub authentication service.
   * @param ConfigFactoryInterface $configFactory
   *   The config factory interface for obtaining configuration settings
   */
  public function __construct(
    protected ClientInterface $client,
    protected KeyRepositoryInterface $keyRepository,
    protected GithubAuth $githubAuth,
    ConfigFactoryInterface $configFactory
  ) {
    $this->config = $configFactory->get('github_issues.settings');
  }

  /**
   * Return a GitHub issue.
   *
   * @param string $issueId
   *   The issue ID.
   *
   * @return array
   *   The requested issue.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function get(string $issueId): array {
    $user = $this->config->get('user');
    $repo = $this->config->get('repository');
    $url = self::API_URL . self::REPOS_ENDPOINT . "/$user/$repo/issues/$issueId";

    $response = $this->client->request('GET', $url, [
      'headers' => $this->buildHeaders(),
    ]);

    return json_decode($response->getBody(), TRUE);
  }
  /**
   * Return GitHub issues.
   *
   * @return array
   *   An array containing the list of GitHub issues.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getAll(): array {
    $user = $this->config->get('user');
    $repo = $this->config->get('repository');
    $url = self::API_URL . self::REPOS_ENDPOINT . "/$user/$repo/issues";

    $response = $this->client->request('GET', $url, [
      'headers' => $this->buildHeaders(),
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * Retrieves issues by a specific tag from the configured user's repository.
   *
   * @param string $tag
   *   The tag to filter the issues by.
   * @param string $value
   *   The value of the tag.
   *
   * @return array
   *   An array containing the retrieved issues.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function getIssuesByTag(string $tag, string $value): array {
    $user = $this->config->get('user');
    $repo = $this->config->get('repository');
    $url = self::API_URL . self::REPOS_ENDPOINT . "/$user/$repo/issues";

    $response = $this->client->request('GET', $url, [
      'headers' => $this->buildHeaders(),
      'query' => [
        'labels' => trim($tag) . ':' . trim($value),
        'state' => 'all', // Optional: includes open and closed issues.
      ],
    ]);

    return json_decode($response->getBody(), TRUE);
  }

  /**
   * Create a new GitHub issue with the given title and body.
   *
   * @param string $title
   *   The title of the new GitHub issue.
   * @param string $body
   *   The body/content of the new GitHub issue.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function create(
    string $title,
    string $body,
    ?string $source = '',
    ?string $author = '',
    ?string $team = ''
  ): void {
    $user = $this->config->get('user');
    $repo = $this->config->get('repository');
    $url = self::API_URL . self::REPOS_ENDPOINT . "/$user/$repo/issues";

    $labels = array_filter([
      $author ? 'author:' . trim($author) : null,
      $source ? 'source:' . trim($source) : null,
      $team ? 'team:' . trim($team) : null,
    ]);

    $this->createLabelsIfNotExist($labels);

    $this->client->request('POST', $url, [
      'headers' => $this->buildHeaders(),
      'json' => [
        'title' => $title,
        'body' => $body,
        'labels' => $labels,
      ],
    ]);
  }

  /**
   * Update a GitHub issue.
   *
   * @param string $issueId
   *   The ID of the issue to edit.
   * @param string $title
   *   The new title for the issue.
   * @param string $body
   *   The new body content for the
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function update(string $issueId, string $title, string $body): void {
    $user = $this->config->get('user');
    $repo = $this->config->get('repository');
    $url = self::API_URL . self::REPOS_ENDPOINT . "/$user/$repo/issues/$issueId";

    $this->client->request('POST', $url, [
      'headers' => $this->buildHeaders(),
      'json' => [
        'title' => $title,
        'body' => $body,
      ],
    ]);
  }

  /**
   * Create GitHub labels if they do not already exist.
   *
   * @param array $labels
   *   An array of labels to create if they do not exist.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public function createLabelsIfNotExist(array $labels): void {
    $user = $this->config->get('user');
    $repo = $this->config->get('repository');
    $url = self::API_URL . self::REPOS_ENDPOINT . "/$user/$repo/labels";

    $response = $this->client->request('GET', $url, [
      'headers' => $this->buildHeaders(),
    ]);
    $existingLabels = json_decode($response->getBody(), TRUE);
    $existingLabelNames = array_map(
      fn($label) => $label['name'],
      $existingLabels
    );

    foreach ($labels as $label) {
      if (in_array($label, $existingLabelNames, TRUE)) {
        continue;
      }

      $labelToCreate = [
        'name' => $label,
        'color' => $this->pickColorForLabel($label),
      ];

      $this->client->request('POST', $url, [
        'headers' => $this->buildHeaders(),
        'json' => $labelToCreate,
      ]);
    }
  }

  /**
   * Build the headers for GitHub API request.
   *
   * @return string[]
   *   An array containing the headers required for GitHub API request.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function buildHeaders(): array {
    return [
      'Authorization' => 'Bearer ' . $this->githubAuth->getToken(),
      'Accept' => 'application/vnd.github.v3+json',
    ];
  }

  protected function pickColorForLabel(string $label): string {
    $colorsMap = [
      'author' => 'f29513',
      'source' => '0e8a16',
      'team' => '5319e7',
    ];

    $labelName = explode(':', $label)[0];

    return $colorsMap[$labelName] ?? '';
  }

}
