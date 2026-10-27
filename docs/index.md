# omnilab/obis

The Ocean Biodiversity Information System's API v3 (`https://api.obis.org/v3/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): a marine taxon by its WoRMS
AphiaID or name - its record counts, NCBI id - as an entity; the records of a box or around a
point, by taxon, depth, dataset, each with its depth and the sea surface temperature and salinity
at its place, as occurrences. No key.

## Installation

```sh
composer require omnilab/obis
```

```php
use Omnilab\Obis\ObisSourceFactory;

$obis = (new ObisSourceFactory($httpClient))->create([
    'mailto' => 'support@glitchr.io',  // optional, in the User-Agent
    'statistics' => true,              // optional: entity() asks for the taxon's records
    'radius_km' => 10,                 // optional: the circle around a Point
]);
```

With the Symfony bundle, the factory is registered as `obis` (see the core's docs).

## Credentials

None: the OBIS API is open, no key, no account.

## Options

The factory's options, with the defaults it applies (`ObisSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `statistics` | `bool` | `true` | no | `entity()` asks for the taxon's records too (a second call; up to 8 s for a kingdom): `records`, `datasets` and the years covered |
| `radius_km` | `float` | `10` | no | the circle around a `Point`, in kilometres, for `occurrences()` when the query gives no radius |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: OBIS publishes no rate |
| `base_uri` | `string` | `https://api.obis.org/v3/` | no | the API the source calls |

Per call, `occurrences()` reads the filters `OccurrenceQuery::TAXON`, `ObisSource::MIN_DEPTH`,
`MAX_DEPTH`, `DATASET` and `RED_LIST`; any other key is refused before any call
([Occurrences](#occurrences)).

## Capabilities

`capabilities()` answers `entity`, `occurrences`, `area`, `cross_references`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | yes | a WoRMS AphiaID (`137094`, `worms:`, its LSID, its WoRMS page), a name, or an OBIS record (`obis.occurrence:` its UUID); another scheme refused before any call |
| `search` | no | OBIS has no search of names here: `entity()` reads a name |
| `series` | no |  |
| `products` | no |  |
| `occurrences` | yes | the records of a box, a circle or the whole ocean, by taxon, depth, dataset, Red List; whole days; OBIS's order, 9,999 a page at most, the next after the last id, with a `total` |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | yes | a taxon's NCBI taxon; a record's iNaturalist observation |
| `forecast` | no |  |
| `historical` | no |  |
| `area` | yes | a `BBox` (across the antimeridian too) or a `Point` and a radius |
| `station` | no |  |
| `cloud_cover` | no |  |

## Which API

The OBIS API v3, `https://api.obis.org/v3/` (OpenAPI at `api.obis.org/obis_v3.yml`):
`taxon/{id}` or `taxon/{name}` for a taxon, `statistics?taxonid=` for its counts,
`occurrence?...` for a query's records, `occurrence/{uuid}` for one.

## Calls

| Method | Reads | OBIS |
|---|---|---|
| `entity()` | an AphiaID: `137094`, `worms:137094`, `aphia:137094`, `urn:lsid:marinespecies.org:taxname:137094`, its WoRMS page | `taxon/137094`, `statistics?taxonid=137094` |
| | a scientific name as OBIS writes it: `Delphinus delphis`; an unaccepted name gives its own record (`Delphinus albimanus`), the accepted taxon related | `taxon/Delphinus delphis`, then the statistics |
| | a record: `obis.occurrence:00007235-5211-4633-98bb-0f8f9ad53e0f`, its API address | `occurrence/{uuid}` |
| `occurrences()` | an `OccurrenceQuery` (below) | `occurrence?...&fields=...&size=` |

No `search()`: OBIS's API has no search of names (its `taxon` path takes an exact name;
`omnilab/worms` searches the same taxonomy). An identifier of another scheme (a GBIF key, an NCBI
taxon, an ITIS TSN, a Wikidata item) is refused before any call. Unknown is null (OBIS answers an
empty list).

## What a taxon holds

`id` (`worms:137094`), `label` (its scientific name), `domain` taxonomy, `kind` (its rank, lower
case), `synonyms` (its vernacular names: OBIS gives no language), `url` (`obis.org/taxon/`), and:

| Key | OBIS | Value |
|---|---|---|
| `taxon_name`, `taxon_authority`, `scientific_name` | scientificName, scientificNameAuthorship, both | text |
| `taxon_rank`, `taxonomic_status` | taxonRank (lower case), taxonomicStatus (`accepted`, `unaccepted`...) | text |
| `accepted_name` | acceptedNameUsage, when another name | text |
| `kingdom`, `phylum`, `class`, `order`, `family`, `genus` | those ranks (GBIF's and NCBI's keys) | text |
| `lineage` | every rank above it, the root first | text |
| `marine`, `brackish`, `freshwater`, `terrestrial` | is_marine... (WoRMS's flags) | boolean |
| `common_name` | the first vernacular name | text |
| `records`, `datasets`, `first_year`, `last_year` | `statistics`: records, datasets, yearrange | number |

`identifiers`: `ncbi.taxon` (ncbi_id), which the `Collector` follows. `related`: `parent` (the
nearest rank above with its AphiaID), `accepted` for an unaccepted name.

## Occurrences

| `OccurrenceQuery` | OBIS |
|---|---|
| `place`: a `BBox` | `geometry=POLYGON((...))`; across the antimeridian the east past 180° (`170 -20,190 -20,...`), which OBIS reads as the box between (checked on 2026-10-15: 963,372 records, its two halves' 325,585 + 637,787) |
| `place`: a `Point` and `radius` | the circle as a polygon of 64 sides on it (OBIS has no distance query); `radius_km` (10) when the query gives none |
| `place`: none | the whole ocean |
| `from`, `to` | `startdate`, `enddate`: whole days; a bound with a time of day is refused (OBIS answers 500 "Invalid date format") |
| `OccurrenceQuery::TAXON` | an AphiaID or an `Identifier` of `worms`: `taxonid` (its subordinate taxa included); anything else, a name: `scientificname` |
| `ObisSource::MIN_DEPTH`, `MAX_DEPTH` (`min_depth`, `max_depth`) | `startdepth`, `enddepth`, in metres |
| `ObisSource::DATASET` (`dataset`) | `datasetid`, an OBIS dataset's UUID |
| `ObisSource::RED_LIST` (`red_list`) | `redlist=true`: the species of the IUCN Red List |
| `sort` | none: OBIS's order, by id; any sort is refused |
| `limit`, `cursor` | `size` (one more asked, to know whether a page follows; 9,999 at most, OBIS refusing past 10,000), `after` the last id |

OBIS leaves out by default the absences and the records its quality control dropped. A record
without a date is left out of the page (every occurrence has its time): a page may hold fewer than
asked. The total is OBIS's, on every page. Any other filter (`COUNTRY`: OBIS knows areas by its own
ids) is refused before any call.

A record holds: `id` (`obis.occurrence:` its UUID), `label` (the taxon, its locality or water
body, its day), `kind` `occurrence`, `time` (the start of its eventDate when written with its
offset; otherwise its first day, midnight UTC - a time without an offset is no instant), `place`,
`url` (its API address: OBIS has no page for a record), `identifiers` (the iNaturalist observation
when iNaturalist published it), `related` (`taxon`: its AphiaID), and:

| Key | OBIS | Value |
|---|---|---|
| `taxon_name`, `taxon_authority`, `taxon_rank`, `common_name` | scientificName... vernacularName | text |
| `kingdom` ... `genus` | its classification | text |
| `basis_of_record`, `event_date` | basisOfRecord, eventDate as published (a range stays a range) | text |
| `depth`, `min_depth`, `max_depth` | depth, else the middle of the two; minimum/maximumDepthInMeters | Quantity, m |
| `coordinate_uncertainty` | coordinateUncertaintyInMeters | Quantity, m |
| `individual_count` | individualCount | number |
| `country`, `locality`, `water_body`, `recorded_by`, `institution` | as published | text |
| `dataset`, `dataset_id`, `license`, `occurrence_id` | datasetName, dataset_id, license, the publisher's occurrenceID | text |
| `sea_surface_temperature`, `sea_surface_salinity` | sst (°C), sss (PSU): what OBIS's quality control gives the place | Quantity |
| `bathymetry`, `shore_distance` | the sea floor's depth and the distance to the shore at the place (negative on land) | Quantity, m |
| `flags` | OBIS's quality flags (`NO_DEPTH`, `ON_LAND`...) | text |

## What OBIS does not give here

- No search of names, no vernacular names' languages, no external ids but NCBI's (WoRMS gives
  ITIS, BOLD, IUCN: `omnilab/worms`).
- Not read: the MeasurementOrFact and DNA extensions, the checklists, grids and tiles, the
  datasets', nodes' and institutes' records, OBIS's own areas.

## The Collector

- `collect('obis', '137094', 1, ['ncbi'])`: OBIS gives the NCBI taxon 9728, which the `Collector`
  follows into NCBI Taxonomy.
- An OBIS record published by iNaturalist carries its observation: `follow($record, 1,
  ['inaturalist'])`.
- With `omnilab/worms`, an entity of either is keyed `worms:`: the `Merger` takes them for one.

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\BBox;
use Omnilab\Model\Scheme;
use Omnilab\Obis\ObisSourceFactory;
use Omnilab\Source\OccurrenceQuery;
use Symfony\Component\HttpClient\HttpClient;

$obis = (new ObisSourceFactory(HttpClient::create()))->create(['mailto' => 'you@example.org']);

$dolphin = $obis->entity('137094');   // a WoRMS AphiaID
echo $dolphin->label, ' (', $dolphin->id, '): ', $dolphin->value('records'), ' records, ', $dolphin->value('datasets'), ' datasets, NCBI ', $dolphin->identifier(Scheme::NCBI_TAXON), "\n";

// The common dolphins of the Bay of Biscay in 2023.
$page = $obis->occurrences(new OccurrenceQuery(new BBox(-10, 43, -1, 48), new DateTimeImmutable('2023-01-01'), new DateTimeImmutable('2023-12-31'), [OccurrenceQuery::TAXON => '137094'], limit: 3));
printf("%d records; the first %d:\n", $page->total, count($page));
foreach ($page as $record) {
    echo '  ', $record->time->format('Y-m-d'), '  ', $record->place, '  ', $record->value('dataset'), ' (', $record->value('license'), ")\n";
}
```

```
Delphinus delphis (worms:137094): 121762 records, 375 datasets, NCBI 9728
30 records; the first 3:
  2023-08-16  47.4735779112,-5.0915610701  iNaturalist research-grade observations (https://creativecommons.org/licenses/by-nc/4.0/)
  2023-11-23  43.5567746554,-8.2630504094  iNaturalist research-grade observations (https://creativecommons.org/licenses/by-nc/4.0/)
  2023-08-29  45.1284312985,-1.1865784345  iNaturalist research-grade observations (https://creativecommons.org/licenses/by-nc/4.0/)
```

(as answered on 2026-10-27: three calls - the taxon, its statistics, the records)

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        obis: { factory: obis, options: { mailto: '%env(OMNILAB_MAILTO)%', statistics: true, radius_km: 10 } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

OBIS's own: past 10,000 a page it answers 400 (the source asks 9,999 at most); a date with a time
of day its 500 ("Invalid date format"): refused before any call instead. Before any call
(`NotSupportedException`): an identifier of another scheme, a sort, a filter not read, a taxon of
another scheme, a bound with a time of day; a cursor that is not this source's
(`\InvalidArgumentException`).

## Verified, and not

Against the real service on 2026-10-15, without a key: the common dolphin by its AphiaID, its
name, its LSID and its WoRMS page (121,709 records from 1758 to 2026, 374 datasets; NCBI 9728;
*Delphinus* its parent); an unaccepted name (*Delphinus albimanus*, its accepted taxon related);
an unknown id and name; the 30 common dolphins of the Bay of Biscay in 2023 in two pages, all in
the box and the year, all published by iNaturalist; a stranding of 2000-02-19 by its UUID (13.2 °C
at the surface, flagged on land); the 12,314 records within 30 km of Marseille in 2024, the
farthest of the first 50 at 25.4 km; a box across the antimeridian (963,372 records, the sum of
its halves); 3,473 sea cucumbers recorded between 3,000 and 4,000 m; OBIS's 400 past 10,000 a page
and its 500 for a date with a time. The fixtures in `Tests/Fixtures/` are its answers
(`index.json` names each one's address).

Also by curl that day: `datasetid` (the French stranding network's 38,262 records) and `redlist=true`
(14,850,251 records, the first a cod; none for the common dolphin, of least concern).

Again on 2026-10-27: the common dolphin by its AphiaID (121,762 records, 375 datasets), its 30
records in the Bay of Biscay in 2023 (the example above).

Not verified: a 429 (none met; OBIS publishes no rate).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by OBIS or the Intergovernmental Oceanographic Commission of UNESCO. Each record keeps
its dataset's licence (CC0, CC BY or CC BY-NC, in `license`), and OBIS asks that the data be cited
with the datasets used, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-obis#terms-of-use) says. Whoever runs
the package is responsible for complying with them and for any use made of what it returns: see
the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-obis#responsibility)
section.
