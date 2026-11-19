"""Import the development configuration plus explicit E2E-only modules."""
import shutil
from lab import ROOT, LAB, drush
# Verify the clone before preparing an augmented, private configuration source.
drush('php:script',str(ROOT/'tests/e2e/support/assert-isolated.php'))
target=LAB/'config-sync'
shutil.copytree(ROOT/'config/sync',target,dirs_exist_ok=True)
p=target/'core.extension.yml'
s=p.read_text().replace('module:\n','module:\n  labdoo_e2e: 0\n  labdoo_quotes: 0\n  labdoo_theme_preview: 0\n')
p.write_text(s)
drush('config:import','--source='+str(target),'-y')
drush('updatedb','-y')
# Config import restores the production CAPTCHA element. Reapply the test-only
# fixture after seeding, while keeping fresh-lab setup ordering intact.
if (LAB/'state/integration-fixtures.json').exists():
    drush('php:script',str(ROOT/'tests/upgrade/prepare.php'))
# Recopy placements after importing the base theme's final block configuration.
# Installation during config import happens before those blocks are imported.
drush('php:eval', r"$themes=\Drupal::configFactory()->getEditable('system.theme'); $themes->set('default','labdoo')->save(); \Drupal::service('theme_installer')->uninstall(['labdoo_next']); \Drupal::service('theme_installer')->install(['labdoo_next']); $themes->set('default','labdoo_next')->save();")
drush('cr')
