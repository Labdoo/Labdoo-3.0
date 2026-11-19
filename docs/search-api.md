# Search API in Labdoo

This document summarizes the current Search API configuration, the recent changes to include Wiki content in search results, and the most commonly used operational commands.

## Related index and view

- Main index: `default_index` (`config/sync/search_api.index.default_index.yml`)
- Results view: `search_results` (`config/sync/views.view.search_results.yml`)
- Public search path: `/search-results`

## What is indexed for Wiki

In the `entity:mini_wiki_page` datasource, the following text fields are indexed:

- `mini_wiki_label` (Wiki title, with boost)
- `mini_wiki_body` (Wiki content)

Additionally, the view's fulltext filter searches across:

- `mini_wiki_label`
- `mini_wiki_body`
- `title`
- `rendered_item`

This allows Wiki content to appear in search results and then benefit from the datasource type boost.

## Relevant boosts

In `type_boost`:

- `entity:mini_wiki_page`: `datasource_boost: 2.0`
- `entity:node`: `datasource_boost: 1.0`

Note: boost only affects ranking among results that already match; it does not create matches by itself.

## Useful Drush commands

### Import configuration

```bash
vendor/bin/drush cim -y
```

### Recommended reindexing (normal flow)

```bash
vendor/bin/drush search-api:rebuild-tracker
vendor/bin/drush search-api:index default_index
```

### Full reindex (if you need to clear the index)

```bash
vendor/bin/drush search-api:clear default_index
vendor/bin/drush search-api:index default_index
```

### Project helper command

There is also:

```bash
vendor/bin/drush labdoo-search:fix-index
```

This command adjusts `rendered_item` configuration for `node` types and can be useful in some scenarios, but it does not replace configuration import or reindexing when new index fields are added.

## Recommended sequence after Search API changes

```bash
vendor/bin/drush cim -y
vendor/bin/drush search-api:rebuild-tracker
vendor/bin/drush search-api:index default_index
```

If you notice odd behavior or stale content, use the variant with `search-api:clear`.