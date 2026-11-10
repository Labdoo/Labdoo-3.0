#!/usr/bin/env python3
"""Independent, offline integrity audit for the portable translation catalogue.

Run without arguments for adversarial self-tests; --catalog DIR audits real JSON.
No translation provider, network request, Drupal bootstrap or database writes.
"""
import argparse
from collections import Counter
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re
import sys
import tempfile
import unittest

LANGUAGES = {"ar", "bs", "ca", "de", "en", "es", "fr", "hy", "it", "ja",
             "nl", "pl", "ro", "ru", "sr", "sw", "tr", "uk", "zh-hans", "zh-hant"}
TOKENS = re.compile(
    r"\{\{.*?\}\}|\{%.*?%\}|\{\#.*?\#\}|"
    r"(?<![\w])[@!%][A-Za-z_](?:[\w.-]*\w)?|"
    r"%(?:\d+\$)?[-+0 #]*(?:\d+|\*)?(?:\.(?:\d+|\*))?[bcdeEfFgGosuxX]|"
    r"\[[A-Za-z_][\w.-]*(?::[^\]\n]+)+\]|"
    r"\{[A-Za-z_][\w.-]*\}|"
    r"(?<=\{)[A-Za-z_][\w.-]*(?=\s*,\s*(?:plural|select|selectordinal)\b)",
    re.DOTALL,
)
TRANSLATABLE_ATTRS = {"alt", "title", "aria-label", "placeholder"}
UNCHANGED_REASONS = {"brand", "unit", "code", "url", "numeric", "same_word", "already_target_language", "source_language"}
SCRIPT_RANGES = {
    "ar": r"[\u0600-\u06ff]", "hy": r"[\u0530-\u058f]",
    "ru": r"[\u0400-\u04ff]", "uk": r"[\u0400-\u04ff]",
    "ja": r"[\u3040-\u30ff\u3400-\u9fff]",
    "zh-hans": r"[\u3400-\u9fff]", "zh-hant": r"[\u3400-\u9fff]",
}
VOID_TAGS = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link",
             "meta", "param", "source", "track", "wbr"}


def identity(source, context=""):
    return hashlib.sha256((context + "\0" + source).encode("utf-8")).hexdigest()


def pairs_unique(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError("duplicate JSON key: " + key)
        result[key] = value
    return result


def load(path):
    return json.loads(Path(path).read_text(encoding="utf-8"), object_pairs_hook=pairs_unique)


class Markup(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=False)
        self.structure = []

    def handle_starttag(self, tag, attrs):
        # Text-bearing accessibility attributes may be translated; their tokens
        # remain protected by the whole-string token comparison below.
        fixed = sorted((key, value) for key, value in attrs if key not in TRANSLATABLE_ATTRS)
        self.structure.append(("start", tag, fixed))

    def handle_startendtag(self, tag, attrs):
        self.handle_starttag(tag, attrs)
        if tag not in VOID_TAGS:
            self.handle_endtag(tag)

    def handle_endtag(self, tag):
        if tag not in VOID_TAGS:
            self.structure.append(("end", tag))

    def handle_comment(self, data):
        self.structure.append(("comment", data))


def markup(text):
    parser = Markup()
    parser.feed(text)
    parser.close()
    return parser.structure


def integrity_errors(source, target):
    errors = []
    if not isinstance(target, str) or not target.strip():
        return ["empty_or_non_string_translation"]
    if "\ufffd" in target or "\0" in target:
        errors.append("invalid_unicode_or_nul")
    if "\x03" in source:
        if all(re.fullmatch(r"(?:[0-9]+|@count)", form) for form in source.split("\x03")) and any(not re.fullmatch(r"(?:[0-9]+|@count)", form) for form in target.split("\x03")):
            errors.append("non_numeric_counter_translation")
        if any(all(integrity_errors(form, variant) for form in source.split("\x03")) for variant in target.split("\x03")):
            errors.append("invalid_plural_variant")
        return errors
    if "\x03" in target:
        errors.append("unexpected_plural_variant")
    if source == "{Empty}":
        # Webform empty_message: the braces delimit a translated visible label.
        if not re.fullmatch(r"\{[^{}<>\r\n]+\}", target):
            errors.append("empty_message_braces_changed")
        return errors
    if Counter(TOKENS.findall(source)) != Counter(TOKENS.findall(target)):
        errors.append("placeholder_twig_or_token_changed")
    if markup(source) != markup(target):
        errors.append("html_structure_or_fixed_attribute_changed")
    return errors


def audit(directory):
    directory = Path(directory)
    source = load(directory / "source.json")
    strings = source.get("strings")
    if not isinstance(strings, dict) or not strings:
        raise ValueError("source.strings must be a nonempty object")
    if set(source.get("languages", [])) != LANGUAGES:
        raise ValueError("source.languages differs from the 20 configured site languages")
    if len(source["languages"]) != len(LANGUAGES):
        raise ValueError("duplicate language in source.languages")
    for key, entry in strings.items():
        if not isinstance(entry, dict) or not isinstance(entry.get("source"), str) or not isinstance(entry.get("context", ""), str):
            raise ValueError("invalid source entry: " + key)
        if key != identity(entry["source"], entry.get("context", "")):
            raise ValueError("source/context identity mismatch: " + key)
    report = {"source_entries": len(strings), "languages": {}, "errors": [], "warnings": []}
    fingerprints = {}
    for language in sorted(LANGUAGES):
        path = directory / (language + ".json")
        row = {"translated": 0, "approved_identical": 0, "unreviewed_identical": [], "missing": [], "invalid": [], "warnings": []}
        if not path.exists():
            row["missing"] = list(strings)
            report["languages"][language] = row
            continue
        target = load(path)
        if target.get("language") != language or not isinstance(target.get("translations"), dict):
            raise ValueError("target language/structure mismatch: " + str(path))
        unchanged = target.get("unchanged", {})
        if not isinstance(unchanged, dict):
            raise ValueError("target.unchanged must be an object: " + str(path))
        for key, reason in unchanged.items():
            if key not in strings or reason not in UNCHANGED_REASONS or target["translations"].get(key) != strings[key]["source"]:
                report["errors"].append({"language": language, "invalid_unchanged_reason": key})
        comparable = {key: value for key, value in target["translations"].items() if key in strings and value != strings[key]["source"] and not strings[key].get("invariant")}
        if comparable:
            fingerprint = hashlib.sha256(json.dumps(comparable, sort_keys=True, ensure_ascii=False).encode()).hexdigest()
            fingerprints.setdefault(fingerprint, []).append(language)
        extra = set(target["translations"]) - set(strings)
        if extra:
            report["errors"].append({"language": language, "unknown_ids": sorted(extra)})
        for key, entry in strings.items():
            if key not in target["translations"]:
                row["missing"].append(key)
                continue
            value = target["translations"][key]
            errors = integrity_errors(entry["source"], value)
            if errors:
                row["invalid"].append({"id": key, "errors": errors})
            elif value == entry["source"]:
                if entry.get("invariant") in UNCHANGED_REASONS or unchanged.get(key) in UNCHANGED_REASONS:
                    row["approved_identical"] += 1
                else:
                    row["unreviewed_identical"].append(key)
            else:
                row["translated"] += 1
            if isinstance(value, str) and not errors and key not in unchanged and not entry.get("invariant") and language in SCRIPT_RANGES:
                visible = re.sub(r"<[^>]*>", " ", TOKENS.sub("", value))
                if re.search(r"[A-Za-z]{4}", visible) and not re.search(SCRIPT_RANGES[language], visible):
                    row["warnings"].append({"id": key, "reason": "possible_foreign_or_English_residue_without_target_script"})
        report["languages"][language] = row
    for group in fingerprints.values():
        if len(group) > 1:
            report["warnings"].append({"identical_noninvariant_catalog_payloads": group})
    report["complete"] = not report["errors"] and all(not row["missing"] and not row["invalid"] and not row["unreviewed_identical"] for row in report["languages"].values())
    return report


class IntegrityTests(unittest.TestCase):
    def test_language_specific_plural_variants(self):
        self.assertTrue(integrity_errors("1\x03@count", "1\x03@count[2]\x03@count[3]"))
        self.assertEqual(integrity_errors("1 place\x03@count places", "1 miejsce\x03@count miejsca\x03@count miejsc"), [])
        self.assertEqual(integrity_errors("1\x03@count", "@count"), [])
        self.assertEqual(integrity_errors("1 place\x03@count places", "@count emplacement\x03@count emplacements"), [])
        self.assertTrue(integrity_errors("1\x03@count", "1\x03@other"))
        self.assertTrue(integrity_errors("@name: 1\x03@name: @count", "@count"))
        self.assertTrue(integrity_errors("Hello", "Hola\x03Hola"))

    def test_webform_empty_label_is_not_a_placeholder(self):
        self.assertEqual(integrity_errors("{Empty}", "{Vacío}"), [])
        self.assertTrue(integrity_errors("{Empty}", "Vacío"))
        self.assertTrue(integrity_errors("{Empty}", "{<b>Vacío</b>}"))
        self.assertTrue(integrity_errors("{username}", "{nombre}"))

    def fixture(self, directory):
        strings = {identity("School"): {"source": "School", "context": ""},
                   identity("Labdoo"): {"source": "Labdoo", "context": "", "invariant": "brand"}}
        Path(directory, "source.json").write_text(json.dumps({"languages": sorted(LANGUAGES), "strings": strings}))
        for language in LANGUAGES:
            translations = {identity("School"): "School" if language == "en" else "École " + language,
                            identity("Labdoo"): "Labdoo"}
            reasons = {identity("School"): "source_language"} if language == "en" else {}
            Path(directory, language + ".json").write_text(json.dumps({"language": language, "translations": translations, "unchanged": reasons}))

    def test_all_twenty_catalogs_and_explicit_invariants(self):
        with tempfile.TemporaryDirectory() as directory:
            self.fixture(directory)
            result = audit(directory)
            self.assertTrue(result["complete"])
            self.assertEqual(20, len(result["languages"]))
            self.assertEqual(1, result["languages"]["fr"]["approved_identical"])

    def test_english_copy_not_counted_as_translation(self):
        with tempfile.TemporaryDirectory() as directory:
            self.fixture(directory)
            path = Path(directory, "fr.json")
            value = load(path)
            value["translations"][identity("School")] = "School"
            path.write_text(json.dumps(value))
            result = audit(directory)
            self.assertFalse(result["complete"])
            self.assertEqual([identity("School")], result["languages"]["fr"]["unreviewed_identical"])

    def test_missing_catalog_and_stale_unchanged_reason_fail(self):
        with tempfile.TemporaryDirectory() as directory:
            self.fixture(directory)
            Path(directory, "sw.json").unlink()
            path = Path(directory, "fr.json")
            value = load(path)
            value["unchanged"] = {identity("School"): "same_word"}
            path.write_text(json.dumps(value))
            result = audit(directory)
            self.assertFalse(result["complete"])
            self.assertEqual(2, len(result["languages"]["sw"]["missing"]))
            self.assertTrue(result["errors"])

    def test_script_residue_warns_without_automatic_rejection(self):
        with tempfile.TemporaryDirectory() as directory:
            self.fixture(directory)
            result = audit(directory)
            self.assertTrue(result["complete"])
            self.assertTrue(result["languages"]["ar"]["warnings"])

    def test_wrong_source_identity_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            self.fixture(directory)
            path = Path(directory, "source.json")
            value = load(path)
            value["strings"][identity("School")]["source"] = "Changed source"
            path.write_text(json.dumps(value))
            with self.assertRaisesRegex(ValueError, "identity mismatch"):
                audit(directory)

    def test_id_uses_context_and_utf8(self):
        self.assertEqual(identity("Hubs"), hashlib.sha256(b"\0Hubs").hexdigest())
        self.assertNotEqual(identity("Hubs"), identity("Hubs", "menu"))
        self.assertNotEqual(identity("École"), identity("Ecole"))

    def test_duplicate_json_keys_fail(self):
        with self.assertRaises(ValueError):
            json.loads('{"x":1,"x":2}', object_pairs_hook=pairs_unique)

    def test_reordered_placeholders_allowed(self):
        self.assertEqual([], integrity_errors("@name donated %count devices", "%count appareils donnés par @name"))
        self.assertEqual([], integrity_errors("Welcome @name.", "Bonjour @name !"))

    def test_missing_duplicated_or_renamed_placeholder_rejected(self):
        for target in ("Dispositifs", "@name @name", "@nombre"):
            self.assertIn("placeholder_twig_or_token_changed", integrity_errors("@name", target))

    def test_printf_tokens_preserved(self):
        self.assertEqual([], integrity_errors("%1$s: %2$d", "%2$d : %1$s"))
        self.assertTrue(integrity_errors("%1$s", "%s"))

    def test_twig_and_drupal_tokens_preserved(self):
        text = '{% if node %}{{ node.label }} [node:title] {count}{% endif %}'
        self.assertEqual([], integrity_errors(text, text))
        self.assertTrue(integrity_errors(text, text.replace("node.label", "node.titre")))

    def test_html_href_and_tag_structure_preserved(self):
        source = '<a href="/node/1?x=@id" class="more">Read <em>more</em></a>'
        good = '<a class="more" href="/node/1?x=@id">Lire <em>la suite</em></a>'
        self.assertEqual([], integrity_errors(source, good))
        for bad in (good.replace('/node/1', '/node/2'), good.replace('em>', 'strong>'), good.replace('class="more"', 'onclick="bad()"')):
            self.assertTrue(integrity_errors(source, bad))

    def test_accessibility_attributes_may_translate(self):
        self.assertEqual([], integrity_errors('<img src="/a.png" alt="School @name">', '<img alt="École @name" src="/a.png"/>'))

    def test_empty_broken_unicode_and_injected_markup_rejected(self):
        for value in ('', '  ', '\ufffd', 'Text<script>bad()</script>'):
            self.assertTrue(integrity_errors('Text', value))


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--catalog", type=Path)
    arguments = parser.parse_args()
    if arguments.catalog:
        try:
            result = audit(arguments.catalog)
            print(json.dumps(result, ensure_ascii=False, indent=2))
            sys.exit(0 if result["complete"] else 1)
        except (ValueError, OSError) as error:
            print(json.dumps({"fatal": str(error)}))
            sys.exit(2)
    else:
        unittest.main(argv=[sys.argv[0]])
