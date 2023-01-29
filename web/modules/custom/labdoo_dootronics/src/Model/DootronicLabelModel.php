<?php

namespace Drupal\labdoo_dootronics\Model;

/**
 * DootronicLabelModel represents a model for managing labels in the dootronic system.
 *
 * This class holds properties related to a label, including its unique identifier,
 * QR code information, and wattage hour details. It provides methods to access and modify these properties.
 */
class DootronicLabelModel {

  protected string $dootronicId;

  protected string $qrCode;

  protected string $wattageHour;

  public function __construct(
    $dootronicId,
    $qrCode,
    $wattageHour
  ) {
    $this->dootronicId = $dootronicId;
    $this->qrCode = $qrCode;
    $this->wattageHour = $wattageHour;
  }

  public function getDootronicId(): string {
    return $this->dootronicId;
  }

  public function setDootronicId(string $dootronicId): void {
    $this->dootronicId = $dootronicId;
  }

  public function getQrCode(): string {
    return $this->qrCode;
  }

  public function setQrCode(string $qrCode): void {
    $this->qrCode = $qrCode;
  }

  public function getWattageHour(): string {
    return $this->wattageHour;
  }

  public function setWattageHour(string $wattageHour): void {
    $this->wattageHour = $wattageHour;
  }

}
