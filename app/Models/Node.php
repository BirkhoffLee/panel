<?php

namespace Pterodactyl\Models;

use Illuminate\Notifications\Notifiable;
use Pterodactyl\Models\Traits\Searchable;

/**
 * @property int $id
 * @property bool $public
 * @property string $name
 * @property string $description
 * @property int $location_id
 * @property string $fqdn
 * @property string $scheme
 * @property bool $behind_proxy
 * @property bool $maintenance_mode
 * @property int $memory
 * @property int $memory_overallocate
 * @property int $disk
 * @property int $disk_overallocate
 * @property int $upload_size
 * @property string $daemonSecret
 * @property int $daemonListen
 * @property int $daemonSFTP
 * @property string $daemonBase
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property \Pterodactyl\Models\Location $location
 * @property \Pterodactyl\Models\Server[]|\Illuminate\Support\Collection $servers
 * @property \Pterodactyl\Models\Allocation[]|\Illuminate\Support\Collection $allocations
 */
class Node extends Validable
{
    use Notifiable, Searchable;

    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    const RESOURCE_NAME = 'node';

    const DAEMON_SECRET_LENGTH = 36;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'nodes';

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = ['daemonSecret'];

    /**
     * Cast values to correct type.
     *
     * @var array
     */
    protected $casts = [
        'location_id' => 'integer',
        'memory' => 'integer',
        'disk' => 'integer',
        'daemonListen' => 'integer',
        'daemonSFTP' => 'integer',
        'behind_proxy' => 'boolean',
        'public' => 'boolean',
        'maintenance_mode' => 'boolean',
    ];

    /**
     * Fields that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'public', 'name', 'location_id',
        'fqdn', 'scheme', 'behind_proxy',
        'memory', 'memory_overallocate', 'disk',
        'disk_overallocate', 'upload_size',
        'daemonSecret', 'daemonBase',
        'daemonSFTP', 'daemonListen',
        'description', 'maintenance_mode',
    ];

    /**
     * Fields that are searchable.
     *
     * @var array
     */
    protected $searchableColumns = [
        'name' => 10,
        'fqdn' => 8,
        'location.short' => 4,
        'location.long' => 4,
    ];

    /**
     * @var array
     */
    public static $validationRules = [
        'name' => 'required|regex:/^([\w .-]{1,100})$/',
        'description' => 'string',
        'location_id' => 'required|exists:locations,id',
        'public' => 'boolean',
        'fqdn' => 'required|string',
        'scheme' => 'required',
        'behind_proxy' => 'boolean',
        'memory' => 'required|numeric|min:1',
        'memory_overallocate' => 'required|numeric|min:-1',
        'disk' => 'required|numeric|min:1',
        'disk_overallocate' => 'required|numeric|min:-1',
        'daemonBase' => 'sometimes|required|regex:/^([\/][\d\w.\-\/]+)$/',
        'daemonSFTP' => 'required|numeric|between:1,65535',
        'daemonListen' => 'required|numeric|between:1,65535',
        'maintenance_mode' => 'boolean',
        'upload_size' => 'int|between:1,1024',
    ];

    /**
     * Default values for specific columns that are generally not changed on base installs.
     *
     * @var array
     */
    protected $attributes = [
        'public' => true,
        'behind_proxy' => false,
        'memory_overallocate' => 0,
        'disk_overallocate' => 0,
        'daemonBase' => '/srv/daemon-data',
        'daemonSFTP' => 2022,
        'daemonListen' => 8080,
        'maintenance_mode' => false,
    ];

    /**
     * Get the connection address to use when making calls to this node.
     *
     * @return string
     */
    public function getConnectionAddress(): string
    {
        return sprintf('%s://%s:%s', $this->scheme, $this->fqdn, $this->daemonListen);
    }

    /**
     * Returns the configuration in JSON format.
     *
     * @param bool $pretty
     * @return string
     */
    public function getConfigurationAsJson($pretty = false)
    {
        $config = [
            'web' => [
                'host' => '0.0.0.0',
                'listen' => $this->daemonListen,
                'ssl' => [
                    'enabled' => (! $this->behind_proxy && $this->scheme === 'https'),
                    'certificate' => '/etc/letsencrypt/live/' . $this->fqdn . '/fullchain.pem',
                    'key' => '/etc/letsencrypt/live/' . $this->fqdn . '/privkey.pem',
                ],
            ],
            'docker' => [
                'container' => [
                    'user' => null,
                ],
                'network' => [
                    'name' => 'pterodactyl_nw',
                ],
                'socket' => '/var/run/docker.sock',
                'autoupdate_images' => true,
            ],
            'filesystem' => [
                'server_logs' => '/tmp/pterodactyl',
            ],
            'internals' => [
                'disk_use_seconds' => 30,
                'set_permissions_on_boot' => true,
                'throttle' => [
                    'enabled' => true,
                    'kill_at_count' => 5,
                    'decay' => 10,
                    'lines' => 1000,
                    'check_interval_ms' => 100,
                ],
            ],
            'sftp' => [
                'path' => $this->daemonBase,
                'ip' => '0.0.0.0',
                'port' => $this->daemonSFTP,
                'keypair' => [
                    'bits' => 2048,
                    'e' => 65537,
                ],
            ],
            'logger' => [
                'path' => 'logs/',
                'src' => false,
                'level' => 'info',
                'period' => '1d',
                'count' => 3,
            ],
            'remote' => [
                'base' => route('index'),
            ],
            'uploads' => [
                'size_limit' => $this->upload_size,
            ],
            'keys' => [$this->daemonSecret],
        ];

        return json_encode($config, ($pretty) ? JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT : JSON_UNESCAPED_SLASHES);
    }

    /**
     * Gets the location associated with a node.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Gets the servers associated with a node.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function servers()
    {
        return $this->hasMany(Server::class);
    }

    /**
     * Gets the allocations associated with a node.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function allocations()
    {
        return $this->hasMany(Allocation::class);
    }
}
