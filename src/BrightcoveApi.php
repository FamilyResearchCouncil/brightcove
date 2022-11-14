<?php namespace Frc\Brightcove;

use App\Models\Brightcove\Model;
use App\Support\Http\Client\Client;
use App\Support\Http\Client\PendingRequest;
use Frc\Brightcove\Models\BrightcoveModel;
use Frc\Brightcove\Models\Folder;
use Frc\Brightcove\Models\Job;
use Frc\Brightcove\Models\Playlist;
use Frc\Brightcove\Models\Video;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;

class BrightcoveApi extends PendingRequest
{
    private string $client_id;
    private string $client_secret;
    private string $account_id;
    private string $version = 'v1';
    private string $path = '';
    private string $subdomain;
    private string $live_api_key;
    private string $domain;
    private $response_data_key;
    private bool $skip_hydration = false;
    private $hydration_class;

    public function __construct($config)
    {
        $this->client_id = Arr::get($config, 'client_id');
        $this->client_secret = Arr::get($config, 'client_secret');
        $this->account_id = Arr::get($config, 'account_id');
        $this->version = Arr::get($config, 'version', 'v1');
        $this->subdomain = Arr::get($config, 'subdomain', 'cms.api');
        $this->live_api_key = \Arr::get($config, 'live_key', 'cms.api');
        $this->domain = 'brightcove.com';

        parent::__construct();
    }

    public function withoutHydrating()
    {
        $this->skip_hydration = true;

        return $this;
    }

    public function addVideoToFolder($video_id = null, $folder_id = null)
    {
        if (isset($folder_id, $video_id)) {
            $this->folderVideos($folder_id)->put($video_id);
        }

        return $this;
    }

    public function live()
    {
        $this->subdomain = 'api';
        $this->domain = "bcovlive.io";
        $this->version = "v1";

        return $this;
    }

    public function ingest()
    {
        return $this->subdomain('ingest.api');
    }

    public function cms()
    {
        return $this->subdomain('cms.api');
    }

    public function playback()
    {
        return $this->subdomain('edge.api')->version('playback/v1');
    }

    public function subdomain($name)
    {
        $this->subdomain = $name;

        return $this;
    }

    public function path($path = null)
    {
        $this->path = "$path";

        return $this;
    }

    public function video($video_id)
    {
        return $this->hydrateWith(Video::class)
            ->path('videos')
            ->get($video_id);
    }

    public function jobs($id = null)
    {
        return $this->hydrateWith(Job::class)->get(
            trim("jobs/$id", '/')
        );
    }

    public function videos($video_id = null)
    {
        $path = 'videos';
        $this->hydrateWith(Video::class);

        if ($video_id) {
            $path = "$path/$video_id";
        }

        $this->path($path);

        return $this;
    }

    public function playlists($playlist_id = null)
    {
        $this->subdomain('cms.api');
        $this->hydrateWith(Playlist::class);

        $path = 'playlists';

        if ($playlist_id) {
            $path = "$path/$playlist_id";
        }

        $this->path($path);

        return $this;
    }

    public function upload($from_url)
    {
        return $this->post('ingest-requests', [
            'master' => [
                "url" => rtrim($from_url, '#')
            ],
            'callbacks' => [
                'https://api.frc.org/webhooks/brightcove/ingest-notifications'
            ]
        ]);
    }

    public function folders($folder_id = null)
    {
        $this->path('folders');
        $this->hydrateWith(Folder::class);

        if ($folder_id) {
            $this->path .= "/$folder_id";
        }

        return $this;
    }

    public function folderVideos($folder_id)
    {
        $this->folders($folder_id);

        $this->path .= "/videos";

        return $this;
    }

    public function send(string $method, string $url, array $options = [])
    {
        $base_url = "https://$this->subdomain.$this->domain/$this->version";

        if ($this->domain === 'bcovlive.io') {
            // the live api
            $this->withHeaders([
                'X-API-KEY' => $this->live_api_key,
                'Content-Type' => 'application/json',
            ]);

        } else {
            // the default
            $base_url = "$base_url/accounts/$this->account_id";

            $this->withToken($this->accessToken());

            if ($this->requiresPolicyKey()) {
                $this->ensurePolicyKeyHeaderIsPresent();
            }

        }

        $this->baseUrl(trim($base_url, '/') . "/{$this->getPath()}");

        // use this when debugging
//        $baseurl = $this->baseUrl;
//        dump(compact('method', 'url', 'options', 'baseurl'));

        $response = Http::baseUrl($this->baseUrl)
            ->withHeaders($this->options['headers'] ?? [])
            ->send($method, $url, $options);

//        dump($response->json());


        if (!$this->skip_hydration) {
            $data_key = $this->getResponseDataKey();
            $data = $response->json($data_key) ?? $response->json(Str::of($data_key)->singular());
            $keys = $response->collect()->keys();

            if ($data === null && isset($response->collect()->first()['id']) || $response->json('id') !== null) {
                $data = $response->json();
            }

            if ($keys->isNotEmpty() && $data === null) {
                throw new \Exception("No data found at key: '$data_key'. Try setting the response data_key on the BrightcoveModel: $this->hydration_class. Available data keys: " . $keys->join(', '));
            }

            return $this->hydrate($data);
        }


        return $response;
    }

    public function paginate(Callable $closure, string $path = null, array $query = []): void
    {
        $limit = Arr::pull($query, 'limit', 100);
        $offset = Arr::pull($query,'offset', 0);

        do{
            if (isset($response)) {
                $offset += $limit;
            }

            $query = array_merge($query, compact('limit', 'offset'));

            $response = $this->get($path ?? '', $query);

            $output = $closure($response);

        } while ($output !== false && $response->collect()->count() >= $limit);
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return trim($this->path, "/");
    }

    public function version($version)
    {
        $this->version = $version;

        return $this;
    }

    public function accessToken()
    {
        return \Cache::remember("brightcove-token-" . $this->client_id, 300, function () {
            return Http::asJson()->withBasicAuth($this->client_id, $this->client_secret)
                ->post("https://oauth.brightcove.com/v4/access_token?grant_type=client_credentials")
                ->json('access_token');
        });
    }

    public function all()
    {
        return $this->get('');
    }

    public function first()
    {
        $data =  $this->all();

        if ($data instanceof Response) {
            return array_first($data->json());
        }else if ($data instanceof Collection) {
            return $data->first();
        }else if (is_array($data)) {
            return array_first($data);
        }else if ($data instanceof BrightcoveModel) {
            return $data;
        }

        throw new \Exception("Unable to determine the first item in the response.");
    }

    public function create(array $data)
    {
        return $this->post('', $data);
    }

    public function hydrateWith($class, $data_key = null)
    {
        $this->hydration_class = $class;
        $this->skip_hydration = false;

        if ($data_key) {
            $this->response_data_key = $data_key;
        }

        return $this;
    }

    public function hydrate($data)
    {
        if ($this->skip_hydration) {
            return $data;
        }

        if ($class = $this->hydration_class) {
            if (isset($data['id'])) {
                $data = new $class($data, clone $this);
            } else {
                $data = collect($data)->map(function ($i) use ($class) {
                    return new $class($i, clone $this);
                });
            }
        }

        return $data;
    }


    public function requiresPolicyKey()
    {
        return $this->subdomain === 'edge.api';
    }

    public function ensurePolicyKeyHeaderIsPresent()
    {
        $res = Http::withToken($this->accessToken())->contentType('application/json')->asJson()
            ->post("https://policy.api.brightcove.com/v1/accounts/$this->account_id/policy_keys", [
                'key-data' => [
                    'account-id' => $this->account_id
                ]
            ]);

        return $this->withHeaders([
            'BCOV-Policy' => $res->json('key-string')
        ]);
    }

    /**
     * @return string
     */
    public function getResponseDataKey()
    {
        return $this->response_data_key ?? $this->guessResponseDataKey();
    }

    public function guessResponseDataKey(): string
    {
        if ($this->hydration_class && method_exists($this->hydration_class, 'getResponseDataKey')) {
            return (new $this->hydration_class)->getResponseDataKey();
        }

        if ($this->subdomain === 'edge.api') {
            return 'items';
        }

        return 'data';
    }
}
