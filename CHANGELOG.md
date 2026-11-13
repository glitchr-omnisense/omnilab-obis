# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release: it installs with Composer's default minimum-stability (stable).

### Added

- Omnilab OBIS: a marine taxon of the Ocean Biodiversity Information System by its WoRMS AphiaID or
  its name - rank, status, classification, vernacular names, NCBI id, habitat, how many records OBIS
  holds - and the occurrences of a place and a period (by taxon, depth, dataset, Red List species),
  each with its time, place, depth, the sea surface temperature and salinity at its place, its taxon
  related, through the OBIS API v3, no key needed.

### Release

- Requires `omnisense/omnilab` `^1.0`, stable versions, where it required `@dev` ones:
  `composer require omnilab/obis` installs in a project at Composer's default minimum-stability
  (stable). `extra.branch-alias` names the branch (`dev-1.x`: `1.x-dev`).
- CI: `.github/workflows/ci.yml` (glitchr-studio/ci's php-package.yml, pinned: the package
  installed alone, PHPUnit, PHPStan against its baseline, a consumer install) and `release.yml`
  (that CI on the tag, then glitchr-studio/ci's php-release.yml); `phpunit.xml.dist` runs the
  package's own tests.
- README: the install line and a first call.
