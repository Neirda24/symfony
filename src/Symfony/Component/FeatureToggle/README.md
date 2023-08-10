ToggleFeature Component
=======================

The ToggleFeature component provides TODO

**This Component is experimental**.
[Experimental features](https://symfony.com/doc/current/contributing/code/experimental.html)
are not covered by Symfony's
[Backward Compatibility Promise](https://symfony.com/doc/current/contributing/code/bc.html).

Usage
-----

This example implements the following logic :
* **Grants** when the `feat` parameter equals to `true` in the query string.
* **Denies** when the `feat` parameter equals to `false` in the query string.
* **Abstain** when the `feat` parameter is not found in the query string. So it
 fallbacks to `false`.

```php
<?php

use Symfony\Component\FeatureToggle\Feature;
use Symfony\Component\FeatureToggle\FeatureChecker;
use Symfony\Component\FeatureToggle\FeatureCollection;
use Symfony\Component\FeatureToggle\Strategy\RequestQueryStrategy;

$features = new FeatureCollection([
    new Feature(
        name: 'new_feature',
        description: 'My new feature',
        default: false,
        strategy: new RequestQueryStrategy('feat'),
    ),
];
$featureChecker = new FeatureChecker(
    features: $features,
    whenNotFound: false,
);

if ($featureChecker->isEnabled('new_feature')) {
    // Use the new feature
} else {
    // Use the legacy code
}
```

Available strategies
--------------------

**AffirmativeStrategy** TODO

**DateStrategy** TODO

**DenyStrategy** TODO

**EnvStrategy** TODO

**GrantStrategy** TODO

**NotStrategy** TODO

**PriorityStrategy** TODO

**RandomStrategy** TODO

**RequestHeaderStrategy** TODO

**RequestQueryStrategy** TODO

Resources
---------

* [Contributing](https://symfony.com/doc/current/contributing/index.html)
* [Report issues](https://github.com/symfony/symfony/issues) and
  [send Pull Requests](https://github.com/symfony/symfony/pulls)
  in the [main Symfony repository](https://github.com/symfony/symfony)
