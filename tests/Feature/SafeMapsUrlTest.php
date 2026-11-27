<?php

namespace Tests\Feature;

use App\Services\PublicSeo;
use Tests\TestCase;

class SafeMapsUrlTest extends TestCase
{
    public function test_map_links_keep_public_search_queries_but_reject_encoded_credentials(): void
    {
        foreach ([
            'https://www.google.com/maps/search/?api=1&query=Ti%E1%BB%87m+Key',
            'https://maps.app.goo.gl/example',
            'https://maps.apple.com/?q=Key+Street&ll=10,106',
            'https://www.openstreetmap.org/#map=15/10/106',
        ] as $url) {
            $this->assertSame($url, PublicSeo::safeMapsUrl($url));
        }
        foreach (['key=SECRET', '%6bey=SECRET', '%256bey=SECRET', 'a%70i%5Fkey=SECRET', 'ACCESS_TOKEN=SECRET',
            'auth[token]=SECRET', 'auth%5Btoken%5D=SECRET', 'x%5Bapi_key%5D%5B%5D=SECRET', 'signature=SECRET',
            'password=SECRET', 'x=ok;token=SECRET', '%25252525256bey=SECRET'] as $query) {
            $this->assertNull(PublicSeo::safeMapsUrl('https://www.google.com/maps?'.$query));
        }
        foreach (['http://www.google.com/maps', 'https://user:password@www.google.com/maps', 'https://www.google.com:8443/maps',
            'https://www.google.com/mapsevil', 'https://maps.google.com.evil.test/maps', 'https://maps.apple.com/#access_token=SECRET'] as $url) {
            $this->assertNull(PublicSeo::safeMapsUrl($url));
        }
    }
}
