<?php

namespace App\Http\Controllers;

use App\Services\PublicSeo;
use App\Services\Store;
use Inertia\Inertia;

class PublicPages extends Controller
{
    public function about()
    {
        return $this->info('about');
    }

    public function contact()
    {
        return $this->info('contact');
    }

    public function faq()
    {
        return $this->info('faq');
    }

    public function location()
    {
        return $this->info('location');
    }

    private function info(string $kind)
    {
        return Inertia::render('PublicInfo', PublicSeo::info($kind));
    }

    public function robots()
    {
        $settings = Store::settings();
        $body = "User-agent: *\n";
        if (! PublicSeo::indexingEnabled() || ! $settings['business_verified']) {
            $body .= "Disallow: /\n";
        } else {
            foreach (['quan-tri', 'gio-hang', 'dat-banh', 'tra-cuu', 'don-cua-toi', 'thao-tac-don', 'dang-nhap', 'dang-ky', 'quen-mat-khau', 'dat-lai-mat-khau', 'xac-thuc-hai-buoc', 'up'] as $path) {
                $body .= 'Disallow: /'.$path."\n";
            }
            $body .= 'Sitemap: '.PublicSeo::url('/sitemap.xml')."\n";
        }

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }

    public function sitemap()
    {
        $entries = array_map(fn ($url) => '<url><loc>'.htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</loc></url>', PublicSeo::sitemapUrls());
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', $entries).'</urlset>';

        return response($body, 200, ['Content-Type' => 'application/xml; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }
}
