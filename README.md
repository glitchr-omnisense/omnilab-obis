# omnilab/obis

OBIS, the Ocean Biodiversity Information System, for
[omniscient/omnilab](https://github.com/glitchr-studio/omnilab): a marine taxon by its WoRMS AphiaID
(OBIS's taxonomy is the World Register of Marine Species') or its name - its rank, status,
classification, vernacular names, NCBI taxon, habitat, how many records OBIS holds of it and over
which years -, and the records of a place and a period in the core's fourth shape
(occurrences): each with its time, its place, its depth, the sea surface temperature and salinity
OBIS gives its place, its taxon related. No key.

```php
$obis = (new ObisSourceFactory($http))->create(['mailto' => 'support@glitchr.io']);
$dolphin = $obis->entity('137094');               // or 'worms:137094', its LSID, its WoRMS page, 'Delphinus delphis'
$dolphin->label;                                  // Delphinus delphis
$dolphin->value('records');                       // 121709 (on 2026-10-15)
$dolphin->identifier(Scheme::NCBI_TAXON);         // 9728: the Collector follows it

// The common dolphins of the Bay of Biscay in 2023.
$query = new OccurrenceQuery(new BBox(-10, 43, -1, 48), new \DateTimeImmutable('2023-01-01'),
    new \DateTimeImmutable('2023-12-31'), [OccurrenceQuery::TAXON => '137094'], limit: 20);
$page = $obis->occurrences($query);
$page->total;                                     // 30
$page->first()->related('taxon')->keys();         // worms:137094
$obis->occurrences($query->with(['cursor' => $page->next]));   // the next 10

// Sea cucumbers between 3,000 and 4,000 m.
$obis->occurrences(new OccurrenceQuery(filters: [OccurrenceQuery::TAXON => 'Holothuroidea',
    ObisSource::MIN_DEPTH => 3000, ObisSource::MAX_DEPTH => 4000]));
```

```yaml
omnilab:
    sources:
        obis:
            factory: obis
            options:
                mailto: 'support@glitchr.io'   # in the User-Agent
                statistics: true               # entity() asks for the taxon's records too (a second call)
                radius_km: 10                  # the circle around a Point, when a query gives none
```

A taxon is named in the core's `worms` scheme, which `omnilab/worms` reads too: with both
configured the `Merger` joins OBIS's counts to WoRMS's synonyms and cross-references. OBIS gives
the NCBI taxon, which the `Collector` follows. A record iNaturalist published carries the
observation's number (`inaturalist.observation`): `$collector->follow($record)` reads it there.

## Terms of use

The OBIS API is open and needs no key; OBIS publishes no rate limit. Each record keeps its
dataset's licence (CC0, CC BY or CC BY-NC, in `license`), and OBIS asks that the data be cited:
"OBIS (2026) Ocean Biodiversity Information System. Intergovernmental Oceanographic Commission of
UNESCO. www.obis.org", with the datasets used (`dataset`, `dataset_id`). See
[obis.org/data/access](https://obis.org/data/access/) and [obis.org/manual](https://manual.obis.org/).

See [docs/](docs/index.md) for what is read, how it maps, and what OBIS does not give here.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by the Intergovernmental Oceanographic Commission of UNESCO and its Ocean Biodiversity Information System (OBIS). It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
