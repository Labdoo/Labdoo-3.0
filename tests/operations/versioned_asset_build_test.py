"""Verify immutable publication, relative CSS assets and retention of old URLs."""
import hashlib
import importlib.util
import json
import re
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location('static_build', ROOT / 'web/themes/custom/labdoo_next/build/build-static.py')
build = importlib.util.module_from_spec(spec)
spec.loader.exec_module(build)


class VersionedAssetBuildTest(unittest.TestCase):
    def test_template_asset_references_are_published(self):
        theme = ROOT / 'web/themes/custom/labdoo_next'
        manifest = json.loads((theme / 'static/manifest.json').read_text())
        for template in (theme / 'templates').rglob('*.twig'):
            for source in re.findall(r"labdoo_asset\('([^']+)'\)", template.read_text()):
                with self.subTest(template=template.name, asset=source):
                    self.assertTrue((ROOT / 'web' / source).is_file(), source)
                    self.assertIn(source, manifest)
                    self.assertTrue((ROOT / 'web' / manifest[source]).is_file())

    def test_changed_bytes_get_new_urls_and_keep_old_artifacts(self):
        with tempfile.TemporaryDirectory() as directory:
            web = Path(directory)
            source = web / 'example.js'
            output = web / 'static'
            source.write_bytes(b'first version')
            first = build.publish([source], web, output)['example.js']
            source.write_bytes(b'second version')
            second = build.publish([source], web, output)['example.js']
            self.assertNotEqual(first, second)
            self.assertEqual((web / first).read_bytes(), b'first version')
            self.assertEqual((web / second).read_bytes(), b'second version')
            for path in [first, second]:
                self.assertEqual(Path(path).parent.name, hashlib.sha256((web / path).read_bytes()).hexdigest())

    def test_css_relative_images_are_versioned_without_changing_bytes(self):
        with tempfile.TemporaryDirectory() as directory:
            web = Path(directory)
            (web / 'images').mkdir()
            image = web / 'images/icon.png'
            image.write_bytes(b'image fixture')
            source = web / 'map.css'
            source.write_text('.marker { background: url("images/icon.png"); }')
            manifest = build.publish([source], web, web / 'static')
            css = (web / manifest['map.css']).read_text()
            relative = '../' + '/'.join(manifest['images/icon.png'].split('/')[-2:])
            self.assertIn('url(' + relative + ')', css)
            self.assertEqual(((web / manifest['map.css']).parent / relative).resolve().read_bytes(), b'image fixture')

    def test_dependencies_cannot_escape_the_document_root(self):
        with tempfile.TemporaryDirectory() as directory:
            base = Path(directory)
            web = base / 'web'
            web.mkdir()
            (base / 'private.png').write_bytes(b'private')
            source = web / 'map.css'
            source.write_text('div { background:url(../private.png) }')
            with self.assertRaises(ValueError):
                build.publish([source], web, web / 'static')

    def test_vendor_bundle_retains_relative_and_retina_images(self):
        with tempfile.TemporaryDirectory() as directory:
            web = Path(directory)
            vendor = web / 'vendor/leaflet'
            images = vendor / 'dist/images'
            images.mkdir(parents=True)
            (vendor / 'dist/leaflet.css').write_text('div { background:url(images/marker-icon.png) }')
            (vendor / 'MIT-LICENCE.txt').write_text('Synthetic license fixture')
            (images / 'marker-icon.png').write_bytes(b'normal icon')
            (images / 'marker-icon-2x.png').write_bytes(b'retina icon')
            manifest = build.publish([], web, web / 'static', bundles=[vendor])
            self.assertEqual((web / manifest['vendor/leaflet/MIT-LICENCE.txt']).read_text(), 'Synthetic license fixture')
            css = web / manifest['vendor/leaflet/dist/leaflet.css']
            self.assertEqual((css.parent / 'images/marker-icon.png').read_bytes(), b'normal icon')
            self.assertEqual((css.parent / 'images/marker-icon-2x.png').read_bytes(), b'retina icon')


if __name__ == '__main__':
    unittest.main()
