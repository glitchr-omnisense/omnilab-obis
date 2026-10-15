# omnilab/obis

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

Not verified: a 429 (none met; OBIS publishes no rate).
