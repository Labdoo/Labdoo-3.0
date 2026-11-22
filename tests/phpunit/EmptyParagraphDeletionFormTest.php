<?php

declare(strict_types=1);

namespace Labdoo\Tests\Unit;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\natiboo_migrate_data\Form\DeleteParagraphsForm;
use Drupal\natiboo_migrate_data\Service\ParagraphDelete;
use Drupal\Tests\UnitTestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * An empty deletion form must redirect without returning a response as a form.
 */
class EmptyParagraphDeletionFormTest extends UnitTestCase {

  /**
   * No paragraphs means an immediate enforced response and no deletion.
   */
  public function testEmptyFormRedirects(): void {
    $deletion = $this->createMock(ParagraphDelete::class);
    $deletion->method('getParagraphCount')->willReturn(0);
    $deletion->expects($this->never())->method('deleteAllParagraphs');
    $form = $this->getMockBuilder(DeleteParagraphsForm::class)->setConstructorArgs([$deletion])->onlyMethods(['redirect'])->getMock();
    $response = new RedirectResponse('/admin/config/natiboo-migrate/entities-list');
    $form->expects($this->once())->method('redirect')->with('natiboo_migrate_data.entities_list')->willReturn($response);
    $messenger = $this->createMock(MessengerInterface::class);
    $messenger->expects($this->once())->method('addStatus')->willReturnSelf();
    $form->setMessenger($messenger);
    $form->setStringTranslation($this->getStringTranslationStub());
    try {
      $form->buildForm([], new FormState());
      $this->fail('An empty confirmation form must enforce the redirect.');
    }
    catch (EnforcedResponseException $exception) {
      $this->assertSame($response, $exception->getResponse());
    }
  }

}
