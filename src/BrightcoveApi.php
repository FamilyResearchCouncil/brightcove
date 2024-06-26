<?php namespace Frc\Brightcove;

use App\Models\Brightcove\Model;
use App\Support\Http\Client\Client;
use App\Support\Http\Client\PendingRequest;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;
use Brightcove\API\DI;
use Carbon\Carbon;
use Exception;
use Frc\Brightcove\Exceptions\NotFoundException;
use Frc\Brightcove\Models\BrightcoveModel;
use Frc\Brightcove\Models\Folder;
use Frc\Brightcove\Models\Job;
use Frc\Brightcove\Models\Playlist;
use Frc\Brightcove\Models\Video;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Request;
use MiladRahimi\Jwt\Cryptography\Algorithms\Rsa\RS256Signer;
use MiladRahimi\Jwt\Cryptography\Algorithms\Rsa\RS256Verifier;
use MiladRahimi\Jwt\Cryptography\Keys\RsaPrivateKey;
use MiladRahimi\Jwt\Cryptography\Keys\RsaPublicKey;
use MiladRahimi\Jwt\Generator;
use MiladRahimi\Jwt\Parser;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\UriInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

class BrightcoveApi extends PendingRequest
{
    private string $client_id;
    private string $client_secret;
    private string $account_id;
    private string $live_api_key;
    private bool $skip_hydration = false;
    private bool $useJwt = false;
    private $response_data_key;
    private $hydration_class;

    public array $query = [];
    public string $version = 'v1';
    public string $domain;
    public string $path = '';
    public string $subdomain;

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

    public function reset(): BrightcoveApi
    {
        return app('brightcove');
    }

    public function addVideoToFolder($video_id, $folder_id)
    {
        return $this->reset()->withoutHydrating()->folderVideos($folder_id)->throw()->put($video_id);
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

    public function video($video_id, $throw = true): ?Video
    {
        $video = $this->hydrateWith(Video::class)
            ->when($throw, fn($http) => $http->throw())
            ->path('videos')
            ->get("$video_id");

        if ($video instanceof Collection) {
            $video = $video->first();
        }

        return $video?->id == $video_id ? $video : null;
    }

    public function jobs($id = null, $query = [])
    {
        return $this->hydrateWith(Job::class)->get(
            trim("jobs/$id", '/'), $query
        );
    }

    public function videos($video_id = null)
    {
        return $this->cms()
            ->resetQuery()
            ->hydrateWith(Video::class)
            ->path($video_id ? "videos/$video_id" : "videos");
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


    public function uploadVideoFile($video_id, $file_path, $source_name)
    {
        try {
            $s3_details = Http::withToken($this->accessToken())
                ->retry(5, sleepMilliseconds: 5000)
                ->get($ingestUrl = "https://ingest.api.brightcove.com/v1/accounts/$this->account_id/videos/$video_id/upload-urls/$source_name")
                ->collect();
        } catch (\Throwable $e) {
            throw new \Exception("Failed to get the s3 details for the video upload: " . $e->getMessage() . " URL: $ingestUrl");
        }

        if ($s3_details->isEmpty()) {
            throw new \Exception("Failed to get the s3 details for the video upload: " . $s3_details->toJson() . "URL: $ingestUrl");
        }

        $uploader = new MultipartUploader(new S3Client([
            'version'     => 'latest',
            'region'      => 'us-east-1',
            'credentials' => [
                'key'    => $s3_details->get('access_key_id'),
                'secret' => $s3_details->get('secret_access_key'),
                'token'  => $s3_details->get('session_token'),
            ]
        ]), $file_path, [
            'bucket' => $s3_details->get('bucket'),
            'key'    => $s3_details->get('object_key'),
        ]);

        return $uploader->upload();
    }

    public function upload($from_url, $thumbnail, $notifications = false)
    {
        return $this->post('ingest-requests', [
            'profile'   => 'multi-platform-standard-static-with-mp4',
            'master'    => [
                "url" => rtrim($from_url, '#')
            ],
            'callbacks' => [
                url('/api/webhooks/brightcove/ingest-notifications')
            ],
            'poster'    => [
                "url" => $thumbnail
            ],
            'thumbnail' => [
                "url" => $thumbnail
            ],
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
                'X-API-KEY'    => $this->live_api_key,
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

        $this->baseUrl(
            $base_url . '/' . str($this->getPath())->explode('/')->filter()->join('/')
        );

        if ($this->useJwt) {
            $options = $this->appendJwtToQuery($options);
        }

        $response = Http::baseUrl($this->baseUrl)
            ->when($this->throwCallback, fn($http) => $http->throw())
            ->timeout(30)
            ->withHeaders($this->options['headers'] ?? [])
            ->send($method, $url, $options);

        if ($response->json('0.error_code')) {
            $this->handleException($response, $options);
        }


        if ($this->shouldHydrate()) {
            $data_key = $this->getResponseDataKey();

            $data = $response->json($data_key)
                ?? $response->json(str($data_key)->singular());

            $keys = $response->collect()->keys();

            if ($data === null && isset($response->collect()->first()['id']) || $response->json('id') !== null) {
                $data = $response->json();
            }

            if ($keys->isNotEmpty() && $data === null) {
                $optionsString = json_encode($options);
                throw new \Exception("ERROR {$method}ing to {$base_url}/{$url} with {$optionsString}: No data found at key: '$data_key'. Try setting the response data_key on the BrightcoveModel: $this->hydration_class. response: " . $response->collect()->toJson());
            }

            return $this->hydrate($data);
        }

        // reset
        $this->skip_hydration = false;
        $this->path = '';

        return $response;
    }

    public function buildBaseUrl()
    {
        return "https://$this->subdomain.$this->domain/$this->version/accounts/$this->account_id";
    }

    public function shouldHydrate(): bool
    {
        return !$this->skip_hydration;
    }

    public function paginate(callable $closure, string $path = null, array $query = []): void
    {
        $limit = Arr::pull($query, 'limit', 100);
        $offset = Arr::pull($query, 'offset', 0);

        do {
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
        return Cache::remember("brightcove-token-" . $this->client_id, 300, function () {
            return Http::asJson()->withBasicAuth($this->client_id, $this->client_secret)
                ->post("https://oauth.brightcove.com/v4/access_token?grant_type=client_credentials")
                ->json('access_token');
        });
    }

    public function all($query = [])
    {
        try {
            return $this->get('', $query);
        } catch (NotFoundException $e) {
            return collect();
        }
    }

    public function first()
    {
        $data = $this->all();

        if ($data instanceof Response) {
            return array_first($data->json());
        } else if ($data instanceof Collection) {
            return $data->first();
        } else if (is_array($data)) {
            return array_first($data);
        } else if ($data instanceof BrightcoveModel) {
            return $data;
        }

        throw new \Exception("Unable to determine the first item in the response.");
    }

    public function last()
    {
        $data = $this->all();

        if ($data instanceof Response) {
            return array_last($data->json());
        } else if ($data instanceof Collection) {
            return $data->last();
        } else if (is_array($data)) {
            return array_last($data);
        } else if ($data instanceof BrightcoveModel) {
            return $data;
        }

        throw new \Exception("Unable to determine the last item in the response.");
    }


    public function create(array $data): Video
    {
        return $this->post('', $data);
    }

    public function hydrateWith($class, $data_key = null)
    {
        $this->hydration_class = $class;

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
        $res = Cache::rememberForever('BCOV-Policy', function () {
            return Http::withToken($this->accessToken())->contentType('application/json')->asJson()
                ->post("https://policy.api.brightcove.com/v1/accounts/$this->account_id/policy_keys", [
                    'key-data' => [
                        'account-id' => $this->account_id
                    ]
                ]);
        });

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

    private function mergeQuery(array $options): array
    {
        $query = collect();

        $query->push(data_get($options, 'query.query') ?? null);
        $query->push($this->query['query'] ?? null);
        $query = $query->flatten()->filter();

        $query = $query->when($query->count() > 1,
            // join multiple queries
            fn($c) => $c->map(fn($q) => "($q)")
                ->join(' AND '),

            // just use the first
            fn($c) => $c->first()
        );

        if (!empty($query)) {
            data_set($options, 'query.query', $query);
        }

        return $options;
    }

    public function where($key, $value, $not = false)
    {
        $value = str($value);

        if (!$value->startsWith('[')) {
            $value = $value->wrap('"');
        }

        $value = "$key:$value";

        $value = $not ? "-$value" : "+$value";

        $this->query['query'][] = $value;

        return $this;
    }

    public function after(Carbon $time)
    {
        $time = $time->format('Y-m-d\T00:00:00.000\Z');

        return $this->where('created_at', "[$time TO *]");
    }

    public function before(Carbon $time)
    {
        $time = $time->format('Y-m-d\T00:00:00.000\Z');

        return $this->where('created_at', "[* TO $time]");
    }

    private function handleException(Response $response, $options)
    {
        /** @var \GuzzleHttp\Psr7\Request $request */
        $request = $response->transferStats->getRequest();
        $error = $response->json('0.error_code');
        $message = $response->json('0.message');


        $message = "\nBrightcove $error ({$response->status()}): $message
            Request: {$request->getMethod()} {$request->getUri()}\nOptions: " . json_encode($options, JSON_PRETTY_PRINT) . "\nApi: " . json_encode($this, JSON_PRETTY_PRINT) . "\nResponse: " . json_encode($response->json(), JSON_PRETTY_PRINT) . "\nHeaders: " . json_encode($request->getheaders(), JSON_PRETTY_PRINT);

        match ((int)$response->status()) {
            404 => throw new NotFoundException($message),
            default => throw new Exception($message)
        };
    }

    private function resetQuery()
    {
        $this->query = [];

        return $this;
    }

    public function withJwt()
    {
        $this->useJwt = true;

        return $this;
    }

    public function appendJwtToQuery($options)
    {
        data_set($options, 'query.bcov_auth', $this->createJwt());

        return $options;
    }

    public function getKeys()
    {
        return Http::withToken($this->accessToken())
            ->throw()
            ->contentType('application/json')
            ->get("https://playback-auth.api.brightcove.com/v1/accounts/$this->account_id/keys");
    }

    public function deleteKey($id)
    {
        return Http::withToken($this->accessToken())
            ->throw()
            ->delete("https://playback-auth.api.brightcove.com/v1/accounts/$this->account_id/keys/{$id}");
    }

    public function deleteAllKeys()
    {
        return $this->getKeys()->collect()
            ->map->id->map($this->deleteKey(...))
            ->map->json();
    }

    public function createKey($public_key)
    {
        return Http::withToken($this->accessToken())
            ->throw()
            ->contentType('application/json')
            ->post("https://playback-auth.api.brightcove.com/v1/accounts/$this->account_id/keys", [
                'value' => $public_key
            ]);
    }

    public function createJwt()
    {
        $this->ensurePublicKeyExists();

//        $header = json_encode([
//            'alg' => 'RS256',
//            'typ' => 'JWT'
//        ]);
//
//        $payload = json_encode([
//            'accid' => $this->account_id,
//            'iat'   => time(),
////            'exp'   => time() + 60 * 60 * 24,
//        ]);
//
//        $one = Process::run("/var/www/html/jwtgen.sh $header $payload /var/www/html/storage/keys/brightcove/private.pem")->output();

        return tap(
            $this->generateJwt(),
            $this->verifyJwt(...)
        );
    }

    public function generateJwt()
    {
        $disk = \Storage::disk('keys');

        if(!$disk->exists('brightcove/private.pem')) {
            throw new \Exception("Private key not found at: " . $disk->path('brightcove/private.pem'));
        }

        return (new Generator(
            new RS256Signer(
                new RsaPrivateKey($disk->path('brightcove/private.pem'))
            )
        ))->generate();
    }

    public function update($id, $data)
    {
        return $this->asJson()->patch($id, $data);
    }

    public function verifyJwt($jwt)
    {
        (new Parser(
            new RS256Verifier(
                new RsaPublicKey(\Storage::disk('keys')->path('brightcove/public.pem'))
            )
        ))->verify($jwt);
    }

    public function ensurePublicKeyExists()
    {
        $disk = \Storage::disk('keys');

        $this->getKeys()->collect()->filter(function ($record) use ($disk) {
            return str($disk->get('brightcove/public_key.txt'))->is($record['value']);
        })->whenEmpty(function () use ($disk) {
            $this->createKey($disk->get('brightcove/public_key.txt'));
        });
    }
}
