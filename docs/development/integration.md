# Retry-condition integration verification

## Dependency target

The feature targets the next v4 add-on line: PHP `^8.5.13`, CQRS `^5.0`, and Config `^4.0`. CQRS5 uses DI6, Reflection3, and Identity1.1. The inspected current DI/CQRS/Identity sources needed small compatibility updates to admit Config4 and Reflection3. Those changes live on `fix/callback-dependency-baseline-2026-11-26` in the corresponding repositories.

The production manifest keeps semantic major requirements. Development and CI explicitly select the actual support branches in an ignored `.composer-integration.json`; each package declares its own truthful branch alias. No path repository overrides a package version, and no previous-major source is relabeled as the new major. Config4 and Reflection3 are installed from their released versions.

## Reproduce the current development graph

Use PHP 8.5.13 or later and Composer 2. The following commands run from a clean checkout of this feature branch. The alternate manifest leaves the tracked `composer.json` unchanged. The commands pin the exact verified revisions on the matching dependency branch names so Composer uses their declared branch aliases.

```bash
git clone --branch fix/callback-dependency-baseline-2026-11-26 --single-branch https://github.com/componenta/cqrs.git .ci/cqrs
git clone --branch fix/callback-dependency-baseline-2026-11-26 --single-branch https://github.com/componenta/di.git .ci/di
git clone --branch fix/callback-dependency-baseline-2026-11-26 --single-branch https://github.com/componenta/identity.git .ci/identity
git -C .ci/cqrs switch -C fix/callback-dependency-baseline-2026-11-26 16cef01402d08e0fb6498b07169c7a3e71197338
git -C .ci/di switch -C fix/callback-dependency-baseline-2026-11-26 ded9a6dc44db2775c4753e73d9581d70caac6043
git -C .ci/identity switch -C fix/callback-dependency-baseline-2026-11-26 3feb94e0d64956ecdf7b22abed4a8bd9f597abeb
cp composer.json .composer-integration.json
export COMPOSER=.composer-integration.json
composer config --json repositories.componenta-cqrs '{"type":"path","url":".ci/cqrs"}'
composer config --json repositories.componenta-di '{"type":"path","url":".ci/di"}'
composer config --json repositories.componenta-identity '{"type":"path","url":".ci/identity"}'
composer require --no-update \
  componenta/cqrs:dev-fix/callback-dependency-baseline-2026-11-26 \
  componenta/di:dev-fix/callback-dependency-baseline-2026-11-26 \
  componenta/identity:dev-fix/callback-dependency-baseline-2026-11-26
composer update --with-all-dependencies --no-interaction --prefer-dist --no-progress
composer test
composer analyse
```

CI follows the same recipe. The PHP matrix covers 8.5.13 and the current 8.5 patch. A published stable CQRS5/DI6/compatible Identity set can later replace the explicit development selection without changing the package's runtime API.

## Verification record

Verified on 2026-11-26 with PHP 8.5.13, Composer 2.11.2, Pest 4.7.8, PHPUnit 12.5.33, and PHPStan 2.3.7.

| Dependency | Verified revision |
|---|---|
| CQRS5 compatibility branch | `16cef01402d08e0fb6498b07169c7a3e71197338` |
| DI6 compatibility branch | `ded9a6dc44db2775c4753e73d9581d70caac6043` |
| Identity1.1 compatibility branch | `3feb94e0d64956ecdf7b22abed4a8bd9f597abeb` |
| Config | `v4.0.0` (`4685e71d86500a7b51f4a773bb3022dd787fba7f`) |
| Reflection | `v3.0.0` |

### Contract evidence

- Before the feature, the existing package tests passed on the migrated dependency graph: 30 tests, 95 assertions. The 20 new condition cases failed because `Retry` did not accept the `when` argument.
- After implementing conditions, the complete public suite verifies replacement classification, attempt bounds, exact failure/operation identity, condition failures, strict boolean results, unchanged default scheduling, native trait scope, repeated metadata lookup, real Config4/DI6 wiring, and independent interleaved Fibers.
- A condition can suspend in one Fiber while another operation completes its own attempt sequence. By-reference argument reassignment cannot replace the middleware's original operation/failure/counter.
- `composer analyse` checks production code at maximum level. No suppression baseline is used.
- Final current graph: **51 tests / 210 assertions passed**; maximum-level PHPStan passed, Composer strict validation passed, and all production platform requirements passed.
- The CI workflow YAML/PHP matrix was parsed, and the actual dependency-install recipe was executed locally. Remote Actions execution is separate from these local results.

### Lock/retry composition

The companion `cqrs-lock` feature contains `integration/lock-retry.php`. After installing that package's development graph, pass this package's `src` directory:

```bash
php ../cqrs-lock/integration/lock-retry.php ./src
```

The real CQRS5 pipeline and Symfony store observed three handler attempts and decisions `[1, 2]` in both orders. Lock outside retry performed one key computation/acquisition/release; retry outside lock performed three. The resource was held during every attempt and available after the pipeline. This check loads the two source trees explicitly and adds no reciprocal Composer development requirement.
