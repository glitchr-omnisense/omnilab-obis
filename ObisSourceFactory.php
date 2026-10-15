<?php

namespace Omnilab\Obis;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * OBIS: marine taxa by AphiaID or name, and the occurrences of a place and
 * a period - no key needed.
 *
 *   options:
 *     mailto: 'support@glitchr.io'     # in the User-Agent
 *     statistics: true                 # entity() asks for the taxon's records too (a second call)
 *     radius_km: 10                    # the circle around a Point, for occurrences() when the query gives none
 *     throttle: 0                      # seconds between two calls: OBIS publishes no rate
 *     base_uri: 'https://api.obis.org/v3/'
 */
final class ObisSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnilab.factory_name' => 'obis',
            'omnilab.required_options' => [],
            'statistics' => true,
            'radius_km' => 10.0,
            'throttle' => 0.0,
            'base_uri' => ObisSource::BASE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new ObisSource(
            $this->http ?? HttpClient::create(),
            (bool) $c->get('statistics'),
            (float) $c->get('radius_km'),
            (string) $c->get('base_uri', ObisSource::BASE_URI),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
        );
    }
}
