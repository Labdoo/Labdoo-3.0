import os
import re

def get_titles():
    titles = set()
    with open('titles.txt', 'r', encoding='utf-8') as f:
        for line in f:
            t = line.strip()
            if t:
                titles.add(t)
                # Also try with question mark if it doesn't have one
                if not t.endswith('?'):
                    titles.add(t + '?')

    # Add menu labels explicitly
    menu_labels = [
        "Main navigation", "User account menu", "Administration", "Footer", "Tools",
        "Footer Left", "Footer Middle", "Footer Right", "Navegación principal"
    ]
    for label in menu_labels:
        titles.add(label)
    return titles

def extract_translations(po_file, titles):
    translations = {}
    if not os.path.exists(po_file):
        return translations

    with open(po_file, 'r', encoding='utf-8') as f:
        content = f.read()

    # Regular expression for PO entries
    pattern = re.compile(r'msgid "(.*?)"\s+msgstr "(.*?)"', re.DOTALL)
    for match in pattern.finditer(content):
        msgid = match.group(1).replace('\\"', '"')
        msgstr = match.group(2).replace('\\"', '"')
        if msgid in titles and msgstr:
            translations[msgid] = msgstr
    return translations

def update_labdoo_po(lang_code, translations):
    target_file = f'web/modules/custom/labdoo_translations/translations/labdoo_translations.{lang_code}.po'
    if not os.path.exists(target_file):
        # Create empty PO if it doesn't exist
        with open(target_file, 'w', encoding='utf-8') as f:
            f.write(f'msgid ""\nmsgstr ""\n"Language: {lang_code}\\n"\n"Content-Type: text/plain; charset=UTF-8\\n"\n"Content-Transfer-Encoding: 8bit\\n"\n\n')

    # Read existing msgids
    with open(target_file, 'r', encoding='utf-8') as f:
        existing_content = f.read()

    existing_msgids = set(re.findall(r'msgid "(.*?)"', existing_content))

    new_entries = []
    for msgid, msgstr in translations.items():
        if msgid not in existing_msgids:
            # Avoid escaping issues
            safe_msgid = msgid.replace('"', '\\"')
            safe_msgstr = msgstr.replace('"', '\\"')
            new_entries.append(f'msgid "{safe_msgid}"\nmsgstr "{safe_msgstr}"\n\n')

    if new_entries:
        with open(target_file, 'a', encoding='utf-8') as f:
            f.write(''.join(new_entries))
        print(f"Updated {target_file} with {len(new_entries)} entries.")

def main():
    titles = get_titles()
    print(f"Tracking {len(titles)} potential strings.")

    # Map po_outputs/ filenames to lang codes
    lang_map = {
        'es': 'drupal7_es.po',
        'ca': 'drupal7_ca.po',
        'fr': 'drupal7_fr.po',
        'de': 'drupal7_de.po',
        'ar': 'drupal7_ar.po',
        'it': 'drupal7_it.po',
        'ja': 'drupal7_ja.po',
        'nl': 'drupal7_nl.po',
        'pl': 'drupal7_pl.po',
        'ru': 'drupal7_ru.po',
        'tr': 'drupal7_tr.po',
        'uk': 'drupal7_uk.po',
        'zh-hans': 'drupal7_zh-hans.po',
        'zh-hant': 'drupal7_zh-hant.po',
        # Add more if needed
    }

    all_translations = {}
    for lang, po_file in lang_map.items():
        translations = extract_translations(f'po_outputs/{po_file}', titles)
        if translations:
            update_labdoo_po(lang, translations)
            all_translations[lang] = translations

    import json
    with open('web/modules/custom/labdoo_translations/menu_translations.json', 'w', encoding='utf-8') as f:
        json.dump(all_translations, f, indent=2, ensure_ascii=False)
    print(f"Generated JSON with {len(all_translations)} languages.")

if __name__ == '__main__':
    main()
