# Use PHPUnit for unit testing PHP code

---

date: 2022-02-07

status: accepted

tags:
  - drupal
  - php
  - coding
  - testing

deciders:
  - Darren Petersen
  - James Sansbury
  - Marcos Cano
  - Mateu Aguiló Bosch
  - Andrew Berry

---

Using a testing framework like [PHPUnit](https://phpunit.de) in all our projects will speed up test authoring and increase reliability of our tests.

PHPUnit is the most popular framework for unit testing in PHP. It is a well maintained and well documented project. Drupal and Symfony use PHPUnit for their unit tests.

Other options briefly considered: 
  - Custom classes that use the `assert` global funtion
  - [SimpleTest](http://simpletest.sourceforge.net)

## Decision

We will use the PHPUnit framework when writing unit tests for PHP code.

In CI, these tests will be executed using the built-in PHPUnit CLI test runner. Locally tests can be executed using the CLI or IDE integrations.

## Consequences

Using the same tool in all our projects will allow the team to deepen our expertise with PHPUnit, be more efficient when writing tests, and transition to other projects more easily.

© 2020-2022 Lullabot, Inc. This work is licensed under a [Creative Commons Attribution 4.0 International License](http://creativecommons.org/licenses/by/4.0/).
