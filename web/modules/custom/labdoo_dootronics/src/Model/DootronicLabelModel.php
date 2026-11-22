<?php

namespace Drupal\labdoo_dootronics\Model;

/**
 * Stores the values rendered on a dootronic label.
 *
 * This class holds properties related to a label, including its unique
 * identifier,
 * QR code information, and wattage hour details. It provides methods to access
 * and modify these properties.
 */
class DootronicLabelModel {

  /**
   * The dootronic identifier printed on the label.
   */
  protected string $dootronicId;

  /**
   * The QR code representation printed on the label.
   */
  protected string $qrCode;

  /**
   * The watt-hour information printed on the label.
   */
  protected string $wattageHour;

  public function __construct(
    $dootronicId,
    $qrCode,
    $wattageHour,
  ) {
    $this->dootronicId = $dootronicId;
    $this->qrCode = $qrCode;
    $this->wattageHour = $wattageHour;
  }

  /**
   * Returns the dootronic identifier printed on the label.
   */
  public function getDootronicId(): string {
    return $this->dootronicId;
  }

  /**
   * Sets the dootronic identifier printed on the label.
   */
  public function setDootronicId(string $dootronicId): void {
    $this->dootronicId = $dootronicId;
  }

  /**
   * Returns the label's QR code representation.
   */
  public function getQrCode(): string {
    return $this->qrCode;
  }

  /**
   * Sets the label's QR code representation.
   */
  public function setQrCode(string $qrCode): void {
    $this->qrCode = $qrCode;
  }

  /**
   * Returns the watt-hour information printed on the label.
   */
  public function getWattageHour(): string {
    return $this->wattageHour;
  }

  /**
   * Sets the watt-hour information printed on the label.
   */
  public function setWattageHour(string $wattageHour): void {
    $this->wattageHour = $wattageHour;
  }

}
