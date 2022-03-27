# Always use typehints in new PHP code

Following stricter typing where available can significantly reduce bugs, and simplify maintainability. This has been proven by several studies across different disciplines.

---

date: 2022-01-12

status: accepted

tags:
  - drupal
  - php
  - coding

deciders:
  - Mateu Aguiló Bosch
  - Andrew Berry

---

See [Case study after adding type hints to urllib3](https://sethmlarson.dev/blog/2021-10-18/tests-arent-enough-case-study-after-adding-types-to-urllib3), and [To type or not to type: quantifying detectable bugs in JavaScript](https://blog.acolyer.org/2017/09/19/to-type-or-not-to-type-quantifying-detectable-bugs-in-javascript/). To quote the latter:

> Both Flow and TypeScript are pretty good, and conservatively either of them can prevent about 15% of the bugs that end up in committed code.

## Decision

We will use type hints in all the new PHP code we write. According to the [official documentation](https://www.php.net/manual/en/language.types.declarations.php) this includes:

  - Type hints in class properties.
  - Type hints for class method parameters and return types.
  - Type hints for procedural function parameters and return types.

Depending on the PHP version the project uses, support may vary. All these are available in PHP 7.4 and newer.

### Examples

Type hints can apply to procedural and object-oriented code.

```php
/**
 * Implements hook_jsonapi_entity_filter_access().
 */
function my_module_jsonapi_entity_filter_access(EntityTypeInterface $entity_type, AccountInterface $account): array {}
```

Note how you can implement a hook or an interface and provide return types even if the hook definition or the interface does not declare one. This is not possible with parameter arguments.

From [the documentation](https://www.php.net/manual/en/language.types.declarations.php):

> When overriding a parent method, the child's method must match any return type declaration on the parent. If the parent doesn't define a return type, then the child method may do so.

The following example illustrates a PHP class with type hints.

 ```php
declare(strict_types=1);

namespace Foo;

final class ExampleClass {
  private string $name = '';
  public function greet(
    string $prefix,
    boolean $isGrumpy,
    Account $user = NULL
  ): ?string {
    return $isGrumpy
      ? null
      : sprintf('Hello %s', $account->userName ?? $this->name);
  }
}
```

Refer to the PHP documentation to learn more about type hints, scalar type hints, and nullable types.

## Consequences

The PHP code we write will be more maintainable, and less prone to have bugs. This will lead to higher efficiency.

© 2020-2022 Lullabot, Inc. This work is licensed under a [Creative Commons Attribution 4.0 International License](http://creativecommons.org/licenses/by/4.0/).
