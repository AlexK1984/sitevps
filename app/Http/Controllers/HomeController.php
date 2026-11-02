<?php

namespace App\Http\Controllers;

use App\Models\SiteContent;
use App\Models\Tariff;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function index(): View
    {
        return view('home', [
            'tariffs' => Tariff::query()->where('is_active', true)->orderBy('price_kopecks')->get(),
            'siteContent' => SiteContent::current(),
            'canonicalUrl' => $this->canonicalUrl(),
        ]);
    }

    public function robots(): Response
    {
        return response(
            "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /dashboard\nDisallow: /login\nDisallow: /register\nSitemap: ".$this->canonicalUrl()."sitemap.xml\n",
            200,
            ['Content-Type' => 'text/plain; charset=UTF-8'],
        );
    }

    public function sitemap(): Response
    {
        $canonicalUrl = $this->canonicalUrl();
        $lastModified = collect([
            SiteContent::query()->whereKey(1)->value('updated_at'),
            Tariff::query()->where('is_active', true)->max('updated_at'),
        ])->filter()
            ->map(fn (string $timestamp): Carbon => Carbon::parse($timestamp))
            ->sort()
            ->last() ?? now();

        $lastModified = $lastModified->toAtomString();
        $location = htmlspecialchars($canonicalUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
            ."<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n"
            ."    <url>\n"
            ."        <loc>{$location}</loc>\n"
            ."        <lastmod>{$lastModified}</lastmod>\n"
            ."        <changefreq>weekly</changefreq>\n"
            ."        <priority>1.0</priority>\n"
            ."    </url>\n"
            .'</urlset>';

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }

    private function canonicalUrl(): string
    {
        $configuredCanonical = SiteContent::current()->canonical_url;

        return rtrim($configuredCanonical ?: config('app.url'), '/').'/';
    }
}
