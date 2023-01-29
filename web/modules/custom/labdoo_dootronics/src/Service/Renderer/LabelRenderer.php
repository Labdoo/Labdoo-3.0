<?php

namespace Drupal\labdoo_dootronics\Service\Renderer;

use Drupal\Core\Entity\EntityInterface;
use Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface;

/**
 * Renders the labels of a dootronic.
 *
 * Developed by Natiboo <info@natiboo.es>
 *
 * @license https://www.gnu.org/licenses/agpl-3.0.en.html GNU AFFERO GENERAL PUBLIC LICENSE
 * @link http://natiboo.es
 */
class LabelRenderer {

  /**
   * The dootronic repository.
   *
   * @var \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface
   */
  protected DootronicRepositoryInterface $dootronicRepository;

  /**
   * LabelRenderer constructor.
   *
   * @param \Drupal\labdoo_dootronics\Service\Repository\DootronicRepositoryInterface $dootronicRepository
   *   The dootronic repository.
   */
  public function __construct(
    DootronicRepositoryInterface $dootronicRepository
  ) {
    $this->dootronicRepository = $dootronicRepository;
  }

  /**
   * Loads a dootronic.
   *
   * @param int $dootronicId
   *   The dootronic ID.
   *
   * @return EntityInterface|null
   *   The dootronic or NULL in case of error.
   */
  public function loadDootronic(int $dootronicId): ?EntityInterface {
    return $this->dootronicRepository->load($dootronicId);
  }

  /**
   * Prints the labels of a dootronic.
   *
   * @param int $dootronicId
   *   The dootronic ID.
   *
   * @return string
   *   The dootronic label.
   */
  public function getDootronicLabel(int $dootronicId): string {
    $dootronic = $this->dootronicRepository->load($dootronicId);
    if ($dootronic === NULL) {
      return '';
    }

    return $dootronic->label();
  }

  /**
   * Generates the QR code.
   *
   * @param string $tag
   *   dooject identifier
   * @param string $size
   *   height of the QR code
   */
  public function getQrCode($tag, $size = '60') {
    $url = urlencode("http://platform.labdoo.org/laptop/".$tag);

    return sprintf(
      '<img src="https://api.qrserver.com/v1/create-qr-code/?size=%sx%s&data=%s"/>',
      $size,
      $size,
      $url
    );
  }

  /**
   * Returns the watt-hours of a dootronic.
   *
   * @param \Drupal\Core\Entity\EntityInterface $dootronic
   *   The dootronic.
   *
   * @return string
   *   The computed watt/hours.
   */
  public function getWattHours(EntityInterface $dootronic): string {
    $volts = $dootronic->get('field_volts')->value;
    $ampHours = $dootronic->get('field_amp_hours')->value;
    if (empty($volts) || empty($ampHours)) {
      return 'Not available';
    }

    $Wh = round($volts * $ampHours / 1000, 1);

    return $Wh . 'Wh';
  }

}
