<?php

namespace Drupal\Tests\labdoo_edoovillage\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\labdoo_common\Service\Helper\LinkHelper;
use Drupal\labdoo_common\Service\Repository\CommonRepository;
use Drupal\labdoo_edoovillage\Plugin\Block\EdooVillageNodeChartBlock;

/**
 * @coversDefaultClass \Drupal\labdoo_edoovillage\Plugin\Block\EdooVillageNodeChartBlock
 * @group labdoo_edoovillage
 */
class EdooVillageNodeChartTest extends UnitTestCase {

  /**
   * Tests labdoo_get_demand function.
   */
  public function testLabdooGetDemand() {
    // Mock the NodeInterface.
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('edoovillage');
    $node->method('hasField')->with('field_number_of_laptops_needed')->willReturn(TRUE);

    // Mock the field item list.
    $fieldItemList = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
    $fieldItemList->value = 15;
    $node->method('get')->with('field_number_of_laptops_needed')->willReturn($fieldItemList);

    // Call the function.
    $demand = labdoo_get_demand($node);
    $this->assertEquals(15, $demand);
  }

  /**
   * Tests the block build method.
   */
  public function testBlockBuild() {
    // Mock LinkHelper.
    $linkHelper = $this->createMock(LinkHelper::class);
    // Mock CommonRepository.
    $commonRepository = $this->createMock(CommonRepository::class);

    // Mock Node.
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('edoovillage');
    $node->method('id')->willReturn(123);
    $node->method('hasField')->with('field_number_of_laptops_needed')->willReturn(TRUE);

    $fieldItemList = $this->createMock(\Drupal\Core\Field\FieldItemListInterface::class);
    $fieldItemList->value = 25;
    $node->method('get')->with('field_number_of_laptops_needed')->willReturn($fieldItemList);

    // Setup mock expectations.
    $linkHelper->method('getActiveNode')->willReturn($node);

    // Mock getDootronicsCountByStatus.
    $commonRepository->method('getDootronicsCountByStatus')
      ->willReturnCallback(function($status, $nid) {
        if ($status === ['T1', 'S3']) {
          return 5;
        }
        if ($status === ['S4', 'S7', 'S8']) {
          return 10;
        }
        if ($status === ['S5', 'S9']) {
          return 2;
        }
        if ($status === 'S6') {
          return 3;
        }
        return 0;
      });

    // Instantiate block.
    $block = new EdooVillageNodeChartBlock(
      [],
      'edoovillage_node_chart_block_block',
      ['provider' => 'labdoo_edoovillage'],
      $linkHelper,
      $commonRepository
    );

    // Build the block.
    $build = $block->build();

    // Assert build array.
    $this->assertEquals('edoovillage_node_chart_block_block', $build['#theme']);
    $this->assertEquals(25, $build['#needed']);
    $this->assertEquals(5, $build['#in_transit']);
    $this->assertEquals(10, $build['#delivered_working']);
    $this->assertEquals(2, $build['#delivered_broken']);
    $this->assertEquals(3, $build['#recycled']);
    $this->assertEquals(20, $build['#tagged']); // 5 + 10 + 2 + 3 = 20
  }

}
