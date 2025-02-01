<?php

namespace Drupal\api_sentinel\Authentication;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Authentication\AuthenticationProviderInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Provides authentication via API keys with rate limiting.
 *
 * @AuthenticationProvider(
 *   id = "api_sentinel_auth",
 *   label = @Translation("API Sentinel Authentication"),
 *   description = @Translation("Authenticates users via API keys with rate limiting.")
 * )
 */
class ApiSentinelAuthProvider implements AuthenticationProviderInterface
{

  /**
   * The database connection.
   *
   * @var Connection
   */
  protected Connection $database;

  /**
   * The cache service for rate limiting.
   *
   * @var CacheBackendInterface
   */
  protected CacheBackendInterface $cache;

  /**
   * The logger service.
   *
   * @var LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs an API Sentinel authentication provider.
   *
   * @param Connection $database
   *   The database connection.
   * @param CacheBackendInterface $cache
   *   The configuration factory.
   * @param LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(Connection $database, CacheBackendInterface $cache, LoggerInterface $logger) {
    $this->database = $database;
    $this->cache = $cache;
    $this->logger = $logger;
  }

  /**
   * Authenticates the user based on an API key.
   *
   * @param Request $request
   *   The request object.
   *
   * @return bool
   *   The authenticated user or NULL if authentication fails.
   */
  public function applies(Request $request): bool
  {
    // Check if an API key is provided in headers or query parameters.
    return $request->headers->has('X-API-KEY') || $request->query->has('api_key');
  }

  /**
   * Authenticates the user based on an API key with rate limiting.
   */
  public function authenticate(Request $request): \Drupal\Core\Entity\EntityInterface|\Drupal\Core\Entity\EntityBase|User|AccountInterface|null
  {
    $config = \Drupal::config('api_sentinel.settings');
    $clientIp = $request->getClientIp();
    $currentPath = \Drupal::service('path.current')->getPath();

    $whitelist = $config->get('whitelist_ips') ?? [];
    $blacklist = $config->get('blacklist_ips') ?? [];
    $customHeader = $config->get('custom_auth_header') ?? 'X-API-KEY';
    $allowedPaths = $config->get('allowed_paths') ?? [];

    // Block request if IP is blacklisted.
    if (in_array($clientIp, $blacklist)) {
      $this->logger->warning('Access denied: IP {ip} is blacklisted.', ['ip' => $clientIp]);
      return NULL;
    }

    // Deny access if whitelist is set and IP is not in it.
    if (!empty($whitelist) && !in_array($clientIp, $whitelist)) {
      $this->logger->warning('Access denied: IP {ip} is not whitelisted.', ['ip' => $clientIp]);
      return NULL;
    }

    // Check if the requested path is allowed.
    $allowed = empty($allowedPaths);
    foreach ($allowedPaths as $pattern) {
      $regexPattern = str_replace('*', '.*', preg_quote($pattern, '/'));
      if (preg_match('/^' . $regexPattern . '$/', $currentPath)) {
        $allowed = TRUE;
        break;
      }
    }

    if (!$allowed) {
      \Drupal::logger('api_sentinel')->warning('Access denied: Path {path} is not allowed.', ['path' => $currentPath]);
      return NULL;
    }

    // Retrieve API key from headers or query parameters.
    $apiKey = $request->headers->get($customHeader, $request->query->get('api_key'));

    if (!$apiKey) {
      $this->logger->warning('Authentication failed: No API key provided.');
      return NULL;
    }

    // Check if the API key exists in the database.
    $query = $this->database->select('api_sentinel_keys', 'ask')
      ->fields('ask', ['uid'])
      ->condition('ask.api_key', $apiKey)
      ->execute()
      ->fetchAssoc();

    if (!$query || empty($query['uid'])) {
      $this->logger->warning('Authentication failed: Invalid API key.');
      return NULL;
    }

    $uid = $query['uid'];

    // Apply rate limiting (max 100 requests per hour).
    $cacheKey = "api_sentinel_rate_limit:$uid";
    $rateLimit = $this->cache->get($cacheKey);

    if ($rateLimit && $rateLimit->data >= 100) {
      $this->logger->warning("Rate limit exceeded for user ID $uid.");
      return NULL;
    }

    // Increment request count.
    $this->cache->set($cacheKey, ($rateLimit ? $rateLimit->data + 1 : 1), time() + 3600);

    // Load the user.
    $user = User::load($uid);

    if ($user && $user->isActive()) {
      $this->logger->info('User {uid} authenticated successfully via API key.', ['uid' => $uid]);
      return $user;
    }

    $this->logger->warning("Authentication failed: User ID $uid is not active.");
    return NULL;
  }
}
