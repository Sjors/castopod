<?php

declare(strict_types=1);

/**
 * @copyright  2022 Ad Aures
 * @license    https://www.gnu.org/licenses/agpl-3.0.en.html AGPL3
 * @link       https://castopod.org/
 */

namespace App\Controllers;

use App\Entities\Podcast;
use App\Models\EpisodeModel;
use App\Models\PodcastModel;
use CodeIgniter\Controller;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\ResponseInterface;
use Exception;
use Modules\PremiumPodcasts\Entities\Subscription;
use Modules\PremiumPodcasts\Models\SubscriptionModel;
use Opawg\UserAgentsV2Php\UserAgentsRSS;

class FeedController extends Controller
{
    /**
     * Instance of the main Request object.
     *
     * @var IncomingRequest
     */
    protected $request;

    public function index(string $podcastHandle): ResponseInterface
    {
        $podcast = (new PodcastModel())->where('handle', $podcastHandle)
            ->first();
        if (! $podcast instanceof Podcast) {
            throw PageNotFoundException::forPageNotFound();
        }

        // 301 redirect to new feed?
        $redirectToNewFeed = service('settings')
            ->get('Podcast.redirect_to_new_feed', 'podcast:' . $podcast->id);

        if ($redirectToNewFeed && $podcast->new_feed_url !== null && filter_var(
            $podcast->new_feed_url,
            FILTER_VALIDATE_URL
        ) && $podcast->new_feed_url !== current_url()) {
            return redirect()->to($podcast->new_feed_url, 301);
        }

        helper(['rss', 'premium_podcasts', 'misc']);

        $service = null;
        try {
            $service = UserAgentsRSS::find(service('superglobals')->server('HTTP_USER_AGENT'));
        } catch (Exception $exception) {
            // If things go wrong the show must go on and the user must be able to download the file
            log_message('critical', $exception->getMessage());
        }

        $serviceSlug = '';
        if ($service) {
            $serviceSlug = $service['slug'];
        }

        $subscription = null;
        $token = $this->request->getGet('token');
        if ($token) {
            $subscription = (new SubscriptionModel())->validateSubscription($podcastHandle, $token);
        }

        $cacheName = implode(
            '_',
            array_filter([
                "podcast#{$podcast->id}",
                'feed',
                $service ? $serviceSlug : null,
                $subscription instanceof Subscription ? "subscription#{$subscription->id}" : null,
            ]),
        );

        if (! ($found = cache($cacheName))) {
            $found = get_rss_feed($podcast, $serviceSlug, $subscription, $token);

            // The page cache is set to expire after next episode publication or a decade by default so it is deleted manually upon podcast update
            $secondsToNextUnpublishedEpisode = (new EpisodeModel())->getSecondsToNextUnpublishedEpisode(
                $podcast->id,
            );

            cache()
                ->save($cacheName, $found, $secondsToNextUnpublishedEpisode ?: DECADE);
        }

        return $this->feedResponse($found);
    }

    private function feedResponse(string $xml): ResponseInterface
    {
        $length = strlen($xml);
        $response = $this->response->setXML($xml)
            ->setHeader('Accept-Ranges', 'bytes')
            ->setHeader('Content-Length', (string) $length);

        // Range only applies to GET. Without validators, an If-Range request
        // must receive the full representation rather than an unchecked slice.
        $range = $this->request->getHeaderLine('Range');
        if (strtoupper($this->request->getMethod()) !== 'GET'
            || $this->request->getHeaderLine('If-Range') !== ''
            || ! preg_match('/^bytes=(\d*)-(\d*)$/D', $range, $matches)
            || ($matches[1] === '' && $matches[2] === '')) {
            return $response;
        }

        if ($matches[1] === '') {
            $start = max(0, $length - (int) $matches[2]);
            $end = $length - 1;
        } else {
            $start = (int) $matches[1];
            $end = $matches[2] === '' ? $length - 1 : min((int) $matches[2], $length - 1);
        }

        if ($start >= $length || $end < $start) {
            return $response->setStatusCode(416)
                ->setHeader('Content-Range', 'bytes */' . $length)
                ->setHeader('Content-Length', '0')
                ->setBody('');
        }

        return $response->setStatusCode(206)
            ->setHeader('Content-Range', "bytes {$start}-{$end}/{$length}")
            ->setHeader('Content-Length', (string) ($end - $start + 1))
            ->setBody(substr($xml, $start, $end - $start + 1));
    }
}
