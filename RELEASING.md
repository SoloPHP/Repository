# Preparing and publishing Solo Repository

The first planned release is **1.0.0** of `solophp/repository`, with namespace
`Solo\Repository`. The standalone repository is
[SoloPHP/Repository](https://github.com/SoloPHP/Repository), with default branch
`main` and a fresh Git history. Source availability does not imply a tagged
release or publication on Packagist.

Keep `SoloPHP/Base-Repository`, its remote, tags, and `solophp/base-repository`
package unchanged. Do not merge this new package identity into the old product's
release branch. The new product starts its own release history at 1.0.0.

Composer derives stable versions from Git tags. Do not add a `version` field to
`composer.json`. The `dev-main` alias `1.0.x-dev` describes development on `main`;
it does not publish a version. The README installs this branch directly from
GitHub through a Composer VCS repository.

## Verify the release candidate

1. Review [public API](docs/guide/api.md), [migration guide](docs/guide/migration.md),
   and [changelog](CHANGELOG.md) together. Keep method names, named arguments,
   return types, fallback rules, and supported platforms consistent.
2. Run `composer validate --strict`, `composer test`, and the runnable examples:
   `php examples/crud.php`, `php examples/translations.php`,
   `php examples/relations.php`, and `php examples/migration.php`.
   `examples/definitions.php` contains definitions used by the catalog demo.
3. Run `composer test:database` on MySQL and PostgreSQL using the
   [integration instructions](docs/guide/details.md#проверка-на-mysql-и-postgresql).
   Before tagging, require the complete CI matrix for the candidate commit in
   the new repository: PHP 8.3 / DBAL 4.3.*, PHP 8.3 / current compatible DBAL,
   PHP 8.5 / current DBAL, each with SQLite, MySQL 8.4, and PostgreSQL 17.
4. With Node 24, run `npm ci`, `npm audit`, and `npm run build` in `docs/`.
   The workflow validates pull requests and audits all dependencies. Pushes only
   run checks. Deployment requires a manual Documentation workflow dispatch
   with `deploy: true` on `main` in `SoloPHP/Repository`.
5. Inspect `git archive` or `composer archive`: runtime source, Composer metadata,
   README, changelog, and license must be present; tests, examples, CI, Docker
   files, and caches must be excluded. Install in a clean consumer with `--no-dev`
   and run the README example. Verify the new namespace autoloads, and that the
   old package is neither replaced nor provided by the new one.
6. Exercise one real consumer's catalog and export before the first stable tag.
   Check pagination, translated filters, empty-string data, hydration types,
   FK/UNIQUE constraints, and explicit transaction boundaries. The synthetic
   integration suite does not replace migration of an application.

## Documentation toolchain

Use Node 24 to match CI; `docs/package.json` requires at least Node 22.12.
VitePress is pinned to **2.0.0-alpha.20**, a prerelease, because the stable 1.x
dependency ranges retain vulnerable Vite/esbuild versions. This uses the
[upstream VitePress 2 dependency graph](https://github.com/vuejs/vitepress/blob/v2.0.0-alpha.20/package.json)
without overrides. The exact pin makes future prerelease upgrades explicit.

For an upgrade, update both `docs/package.json` and `docs/package-lock.json`,
then run a clean install, the full audit, and the build. Keep dev dependencies
in the audit: the documentation toolchain is declared in `devDependencies`.
Preview the generated site under `/Repository/` and check navigation, local
search, anchors, and the mobile menu after theme upgrades. These Node
dependencies are not included in the PHP package archive.

## Publish only when explicitly requested

1. Verify the candidate in this repository. Date the 1.0.0 changelog entry and
   update installation and release-status text together. If real consumer
   validation is pending, use an explicitly chosen prerelease instead.
2. Create and push the corresponding tag and GitHub release, register the new
   Composer package on Packagist, and confirm the indexed name, version, and
   source URL. Link the migration guide from the release notes.
3. For documentation publication, enable GitHub Pages in this repository, then
   manually run the Documentation workflow on `main` with `deploy` enabled.
   Pushes, pull requests, and default manual runs only build and audit the site.
   The site base `/Repository/` assumes the new repository's GitHub Pages path.

The initial source transfer creates no tags, releases, Packagist registration,
or Pages deployment. Each publication step above requires explicit approval.
