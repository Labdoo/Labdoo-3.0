<?php

namespace Drupal\Tests\labdoo_scraper\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\labdoo_scraper\Service\ScraperService;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\node\Entity\NodeType;
use Drupal\node\Entity\Node;

/**
 * Tests the ScraperService.
 *
 * @group labdoo_scraper
 */
class ScraperServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'path_alias',
    'labdoo_scraper',
  ];

  /**
   * The scraper service.
   *
   * @var \Drupal\labdoo_scraper\Service\ScraperService
   */
  protected $scraperService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('labdoo_scraper', ['labdoo_scraper_results']);
    $this->installSchema('system', ['sequences']);

    $this->scraperService = $this->container->get('labdoo_scraper.scraper_service');
  }

  /**
   * Tests verifySlug method.
   */
  public function testVerifySlug() {
    // Create a node and a path alias.
    $node_type = NodeType::create(['type' => 'page', 'name' => 'Page']);
    $node_type->save();

    $node = Node::create([
      'title' => 'Test Page',
      'type' => 'page',
      'status' => 1,
    ]);
    $node->save();

    $path_alias = PathAlias::create([
      'path' => '/node/' . $node->id(),
      'alias' => '/test-slug',
    ]);
    $path_alias->save();

    // Verify existing alias.
    $info = $this->scraperService->verifySlug('test-slug');
    $this->assertTrue($info['exists']);
    $this->assertEquals('node', $info['entity_type']);
    $this->assertEquals($node->id(), $info['entity_id']);

    // Verify non-existing slug.
    $info = $this->scraperService->verifySlug('non-existing-slug');
    $this->assertFalse($info['exists']);
  }

}
