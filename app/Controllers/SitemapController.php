<?php

declare(strict_types=1);

/**
 * @copyright  2026 Ad Aures
 * @license    https://www.gnu.org/licenses/agpl-3.0.en.html AGPL3
 * @link       https://castopod.org/
 */

namespace App\Controllers;

use App\Models\EpisodeModel;
use App\Models\PageModel;
use App\Models\PodcastModel;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\I18n\Time;
use SimpleXMLElement;

class SitemapController extends Controller
{
    public function index(): ResponseInterface
    {
        $cacheName = 'sitemap';

        if (! ($found = cache($cacheName))) {
            $urlset = new SimpleXMLElement(
                '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>',
            );

            $this->addUrl($urlset, url_to('home'));
            $this->addUrl($urlset, url_to('credits'));
            $this->addUrl($urlset, url_to('map'));

            foreach ((new PageModel())->findAll() as $page) {
                $this->addUrl($urlset, $page->link, $page->updated_at);
            }

            foreach ((new PodcastModel())->getAllPodcasts() as $podcast) {
                $this->addUrl($urlset, $podcast->link, $podcast->updated_at);
                $this->addUrl($urlset, url_to('podcast-episodes', $podcast->handle), $podcast->updated_at);
                $this->addUrl($urlset, url_to('podcast-about', $podcast->handle), $podcast->updated_at);

                $episodes = (new EpisodeModel())
                    ->where('podcast_id', $podcast->id)
                    ->where('`published_at` <= UTC_TIMESTAMP()', null, false)
                    ->orderBy('published_at', 'DESC')
                    ->findAll();

                foreach ($episodes as $episode) {
                    $this->addUrl($urlset, $episode->link, $episode->updated_at);
                }
            }

            $found = (string) $urlset->asXML();

            // Cached for an hour so that scheduled episodes appear once published
            cache()
                ->save($cacheName, $found, HOUR);
        }

        return $this->response->setXML($found);
    }

    private function addUrl(SimpleXMLElement $urlset, string $loc, ?Time $lastmod = null): void
    {
        $url = $urlset->addChild('url');
        $url->addChild('loc', htmlspecialchars($loc, ENT_XML1));

        if ($lastmod instanceof Time) {
            $url->addChild('lastmod', $lastmod->format(DATE_W3C));
        }
    }
}
